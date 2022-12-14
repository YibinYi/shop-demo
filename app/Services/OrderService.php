<?php


namespace App\Services;


use App\Coupon;
use App\Exceptions\CouponCodeException;
use App\Jobs\ClosedOrder;
use App\Order;
use App\OrderItem;
use App\Product;
use App\ProductSku;
use App\ShoppingCart;
use App\User;
use App\UserAddress;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderService
{
    public function store($items, $address_id, $remark = null, Coupon $coupon = null)
    {
        $order = DB::transaction(function () use ($items, $address_id, $remark, $coupon) {

            if ($coupon) {
                $coupon->checkCouponCode();
            }


            $address = UserAddress::query()->find($address_id);
            $address->last_used_at = Carbon::now();
            $order = new Order([
                'total_amount' => 0,
                'address' => [
                    'province' => $address->province,
                    'city' => $address->city,
                    'district' => $address->district,
                    'zip' => $address->zip,
                    'contact_name' => $address->contact_name,
                    'contact_phone' => $address->contact_phone
                ],
                'type' => Product::TYPE_NORMAL
            ]);
            if (!is_null($remark)) {
                $order->remark = $remark;
            }
            $order->user()->associate(Auth::id());

            $order->save();

            $total_amount = 0;
            foreach ($items as $item) {
                $sku = ProductSku::query()->find($item['sku_id']);
                $orderItem = $order->item()->make([
                    'amount' => $item['amount'],
                    'price' => $sku->price,
                ]);
                $orderItem->product()->associate($sku->product);
                $orderItem->sku()->associate($sku);
                $orderItem->save();
                $total_amount += $item['amount'] * $sku->price;
                if ($sku->decrementStock($item['amount']) <= 0) {
                    throw new \Exception('????????????');
                }

            }

            if ($coupon) {
                $coupon->checkCouponCode($total_amount);
                $total_amount = $coupon->discount($total_amount);
                $order->couponCode()->associate($coupon);

                if ($coupon->changeUsed() <= 0) {
                    throw new CouponCodeException('??????????????????');
                }
            }

            $order->update([
                'total_amount' => $total_amount
            ]);

            $ids = collect($order->item)->pluck('product_sku_id')->all();

            (new ShoppingCartService())->remove($ids);

            return $order;
        });
        dispatch(new ClosedOrder($order, config('shop.order.order_ttl')));
        return $order;
    }


    #??????????????????

    /**
     * @param User $user
     * @param ProductSku $sku
     * @param UserAddress $address
     * @param $amount
     * @return mixed
     * @deprecated ???????????????????????? crowdfunding??????
     * @see crowdfunding()
     */
    public function crowdfundingStore(User $user, ProductSku $sku, UserAddress $address, $amount)
    {
        $order = DB::transaction(function () use ($user, $sku, $amount, $address) {
            $order = new Order([
                'extra' => ['order_type' => 'crowdfunding'],
                'total_amount' => $sku->price * $amount,
                'address' => [
                    'address' => $address->full_name,
                    'zip' => $address->zip,
                    'contact_name' => $address->contact_name,
                    'contact_phone' => $address->contact_phone
                ],
                'type' => Product::TYPE_CROWDFUNDING
            ]);

            $order->user()->associate($user);
            $order->save();

            $orderItem = $order->item()->make([
                'amount' => $amount,
                'price' => $sku->price
            ]);

            $orderItem->sku()->associate($sku);
            $orderItem->product()->associate($sku->product);

            $orderItem->save();

            if ($sku->decrementStock($amount) <= 0) {
                throw new \Exception('????????????');
            }
            return $order;
        });
        $crowdfundingTTL = $sku->product->crowdfunding->end_at->getTimestamp() - time();

        dispatch(new ClosedOrder($order, min($crowdfundingTTL, config('shop.order.order_ttl'))));

        return $order;
    }

    /**
     * ????????????
     * @param User $user
     * @param ProductSku $sku
     * @param UserAddress $address
     * @param $amount
     * @return mixed
     * @throws \Exception
     */
    public function crowdfunding(User $user, ProductSku $sku, UserAddress $address,$amount)
    {
        //?????????????????????????????????
        $crowdfundingTTL = $sku->product->crowdfunding->end_at->getTimestamp() - time();
        //????????????????????????????????????????????????????????????
        $ttl = min($crowdfundingTTL, config('shop.order.order_ttl'));

        return $this->createOrder($user, $sku, $address, $amount,Product::TYPE_CROWDFUNDING, $ttl);

    }

    /**
     * ????????????
     * @param User $user
     * @param ProductSku $sku
     * @param UserAddress $address
     * @return mixed
     * @throws \Exception
     */
    public function seckill(User $user, ProductSku $sku, UserAddress $address)
    {
        //??????????????????????????????
        $ttl = config('shop.order.seckill_ttl');

        //?????????????????????????????????
        return $this->createOrder($user, $sku, $address, 1, Product::TYPE_SECKILL, $ttl);


    }

    /**
     * ????????????????????????
     * @param User $user ??????
     * @param ProductSku $sku ??????
     * @param UserAddress $address ????????????
     * @param $amount int ????????????
     * @param $type string ??????????????????????????????????????????????????????????????????
     * @param $ttl int ????????????????????????/?????????
     * @return mixed
     * @throws \Exception
     */
    private function createOrder(User $user, ProductSku $sku, UserAddress $address, $amount, $type, $ttl)
    {
        if (!in_array($type, array_keys(Product::$typeMap))) {
            Log::error('create order error type error' . $type);
            throw new \Exception('????????????');
        }

        $order = DB::transaction(function () use ($user, $sku, $amount, $address, $type) {
            $order = new Order([
                'extra' => ['order_type' => 'crowdfunding'],
                'total_amount' => $sku->price * $amount,
                'address' => [
                    'address' => $address->full_name,
                    'zip' => $address->zip,
                    'contact_name' => $address->contact_name,
                    'contact_phone' => $address->contact_phone
                ],
                'type' => $type
            ]);

            $order->user()->associate($user);
            $order->save();

            $orderItem = $order->item()->make([
                'amount' => $amount,
                'price' => $sku->price
            ]);

            $orderItem->sku()->associate($sku);
            $orderItem->product()->associate($sku->product);

            $orderItem->save();

            if ($sku->decrementStock($amount) <= 0) {
                throw new \Exception('????????????');
            }
            return $order;
        });

        //??????????????????
        dispatch(new ClosedOrder($order, $ttl));

        return $order;
    }

    //????????????
    private function closeOrder($ttl)
    {

    }

    #????????????
    public function handleRefund(Order $order)
    {
        switch ($order->payment_method) {
            case 'alipay':
                $no = Order::createRefundNo();
                $res = app('alipay')->refund([
                    'out_trade_no' => $order->no,
                    'refund_amount' => $order->total_amount,
                    'out_request_no' => $no
                ]);

                if ($res->sub_code) {
                    $extra = $order->extra ?: [];
                    $extra['alipay_refund_fail_code'] = $res->sub_code;
                    $order->update([
                        'refund_no' => $no,
                        'extra' => $extra,
                        'refund_status' => Order::REFUND_STATUS_FAILED,
                    ]);
                } else {
                    $order->update([
                        'refund_status' => Order::REFUND_STATUS_SUCCESS,
                        'refund_no' => $no
                    ]);
                }
                break;
            case 'wechat':
                //todo
                break;
            default:
                Log::error('??????????????????');
                throw new \Exception('????????????');
                break;
        }
    }

}
