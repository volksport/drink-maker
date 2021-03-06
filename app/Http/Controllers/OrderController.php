<?php namespace App\Http\Controllers;
/**
 * Created by PhpStorm.
 * User: marcobellan
 * Date: 26/05/15
 * Time: 17:49
 */
use App\Drink;
use App\flasher;
use App\Http\Controllers\Controller;
use App\Order;
use App\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;


class OrderController extends Controller {
    public function personal(Request $r){
        $drinks= $r->session()->get('order_ids');
        $orders=[];
        if($drinks) {
            $orders = Order::whereIn('id', $drinks)->orderBy('created_at', 'desc')->get();
        }
        return view('personal')->with('orders',$orders);
    }
    public function requeue($id){
        $order= Order::find($id);
        if(!$order || $order->status!=5){
            flasher::error('Error!This order cannot be reordered');
            return redirect()->back();
        }
        $order->status=Settings::initial_status();
        $order->save();
        flasher::success('Your drink has been reordered!');
        return redirect('orders/'.$id);
    }
    public function pending(Request $r){
        if(!$r->ajax())return redirect()->back();
        $orders=Order::whereIn('status',[0,1,2,5,6])->get();
        $status=false;
        foreach($orders as $o){
            if($o['status']==1){
                $status=true;
                break;
            }
        }
        return response(view('admin.orders')->with('orders',$orders)->with('status',$status)->render());
    }
    public function show($id,Request $r){
        $var=$r->session()->get('order_ids');
        if(!in_array($id,$var))
            //is_array($r->session()->get('order_ids'))&&in_array($id,$r->session()->get('order_ids')))))
        {
            flasher::error('You don\'t own this order!');
            return redirect()->back();
        }
        $order=Order::find($id);
        if(!$order){
            flasher::error('We\'re sorry, we can\t find this order');
            return redirect()->back();
        }
        $shouldPlay = ($order->status==3 || $order->status==5) && Settings::play_sounds();
        $before= Order::whereIn('status',[0,1,2,5])->where('id','<',$id)->count();
        return view('order')->with('order',$order)->with('sounds',$shouldPlay)->with('before',$before);
    }
    public function async($id,Request $r){
        if(!$r->ajax())return redirect()->back();
        $order=Order::find($id);
        if(!$order){
            flasher::error('We\'re sorry, we can\t find this order');
            return redirect()->back();
        }
        $shouldPlay = ($order->status==3 || $order->status==5) && Settings::play_sounds();
        $before= Order::whereIn('status',[0,1,2,5])->where('id','<',$id)->count();
        if($before == 0) $before = "no";
        return response(view('order.status')->with('order',$order)->with('sounds',$shouldPlay)
            ->with('before',$before)->render());
    }
    /**
     * Get the drink with the specified name and add it to the orders queue, decrease stock of items
     * @param $req
     * @return \Illuminate\Http\RedirectResponse|\Laravel\Lumen\Http\Redirector
     */
    public function add(Request $req){
        $id=$req->input('id');
        $name=$req->input('name');
        $drink = Drink::find($id);

        if(!$drink->getAvailable()){
            flasher::error('An error occured, please retry later');
            return redirect("order");
        }
        $id=$drink->orderDrink($name);
        flasher::success('We\'re taking care of your order!');
        $drinks=$req->session()->get('order_ids');
        if(!$drinks)$drinks=[];
        array_push($drinks,$id);
        $req->session()->put(['order_ids'=>$drinks]);
        return redirect('orders/'.$id);
    }

    /**
     * Sets a drink in approved mode
     * @param $id
     * @return \Illuminate\Http\RedirectResponse|\Laravel\Lumen\Http\Redirector
     */
    public function approve($id){
        if(DB::table('orders')->where('status',1)->count()==0){
            $order=Order::find($id)->update(['status'=>1]);
            flasher::success('Order set waiting to checkout');
        }else{
            flasher::error('An order has already been taken in charge');
        }
        return redirect("admin#orders");
    }

    /**
     * Gets the cocktail queued for Python
     * @return \Laravel\Lumen\Http\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function waiting(){
        if(Settings::should_shutdown()==true){
            Settings::should_shutdown(false);
            return response("shutdown");
        }
        $order= Order::where('status',1)->orderBy('id','asc')->first();
        if(!$order) return response("none");
        //Create json
        $resp["id"]=$order->id;
        $resp["timeout"]=Settings::timeout_time();
        $resp["start"]=Settings::start_method();
        $resp["volume"]=$order->Drink->volume;
        $resp["lights"]=Settings::has_lights();
        $ing= $order->Drink->Ingredients;
        for($i=0;$i<count($order->Drink->Ingredients);$i++){
            $resp['ingredients'][$i]['position']=$ing[$i]->position;
            $resp['ingredients'][$i]['needed']=$ing[$i]->pivot->needed;
        }
        if(Settings::start_method()==0){
            $order->status=2;    //Making
        }else{
            $order->status=5;   //Waiting activation
        }
        $order->save();
        return response()->json($resp);
    }

    /**
     * Sets an order status to complete
     *
     * @return \Laravel\Lumen\Http\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function completed(){
        DB::table('orders')->where('status','2')->update(['status'=>3]);
        return response('200');
    }
    public function timedOut(){
        DB::table('orders')->where('status','5')->update(['status'=>6]);
        return response('200');
    }
    public function activated(){
        DB::table('orders')->where('status','5')->update(['status'=>2]);
        return response('200');
    }
    /**
     * Find order with given id, put back in stock the various ingredients and delete the order
     * @param $id
     * @return \Illuminate\Http\RedirectResponse|\Laravel\Lumen\Http\Redirector
     */
    public function delete($id){
        $order = Order::find($id);
        if(in_array($order->status,[0,1,2,5,6])) {
            $order->deleteOrder();
            flasher::success('Order deleted correctly');
        }else{
            flasher::error('This order can\'t be deleted');
        }
        return redirect()->back();
    }
}
