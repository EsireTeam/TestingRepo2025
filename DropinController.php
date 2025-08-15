<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;



use Milon\Barcode\Facades\DNS1DFacade;
use Milon\Barcode\Facades\DNS2DFacade;

use Maatwebsite\Excel\Facades\Excel;
use App\Exports\JsonExporters;
use App\Exports\DropInLabelExport;


use DataTables;
use \DB;
use Auth;
use App\Mail\CustMail;
use Exception;
use Carbon;
use Helper;
use App\Exports\LabelLogExcel;

class DropinController extends Controller
{

   public function __construct()
   {
        $this->middleware('auth')->except(['Test','getLabelsId']);
   }

   public function index()
   {
       $data['sup_list'] = DB::table('suppliers')
                            ->where('is_active','=',1)
                            ->where('supptype','=',1)
                            ->get();

       return view('supplychain.dropin',$data);
   }

   public function getPrsbySupId(Request $r)
   {
     $type = $r->dropin_type == 3 ? 1 : 2 ;

     $query = DB::table('fba_pro_pr_items as fi')
    ->select('fi.pr_id', 'oa.account')
    ->leftJoin('fba_pro_purchase_request as fppr', 'fppr.id', '=', 'fi.pr_id')
    ->leftJoin('opexpro_accounts as oa', 'oa.id', '=', 'fppr.store')
    ->where('fi.supplier_id', $r->supplier_id)
    ->where('fi.status', 2)
    ->whereRaw("fi.received_qty < fi.given_qty")
    ->where('fppr.order_type', $type)
    ->groupBy('fi.pr_id')
    ->get();

     return response()->json(['data'=>$query]);

   }

   public function WarehouseOrders($searchItemStatus,$search,$sup_id)
   {
//       SELECT po.order_number,po.ref_number,po.po_date,s.order_date,p.producttitle,p.productimage FROM generate_new_po po
// LEFT JOIN saleorders s ON s.saleorderid = po.saleorderid
// LEFT JOIN productitem p ON p.prodsku = po.opex_sku
// WHERE po.supplier_id=1 AND s.status IN ('In Process','Hold-On')
        $dtp = 0;
        if($sup_id==1)
        {
            $dtp = 1;
        }

        if($sup_id > 1)
        {
            $dtp = 2;
        }

        $query = DB::table('generate_new_po as po');

        $query->selectRaw("($dtp) as dtype,s.country,s.urgent,CONCAT(sup.firstname,' ',sup.lastname) sup_name,po.supplier_id,CONCAT(s.order_sku,' - ',p.producttitle,' | ',po.order_number,' | ',DATE_FORMAT(s.order_date, '%d-%m-%Y'),' | ',s.status,' | ',s.country) as text,po.order_number as id,po.order_number,po.ref_number,po.po_date,s.order_date,p.producttitle,p.productimage,s.status,po.opex_sku,s.order_sku,s.is_prime_delivery");

        $query->leftJoin('saleorders as s','s.saleorderid','=','po.saleorderid')
                 ->leftJoin('productitem as p','p.prodsku','=','po.opex_sku')
                 ->leftJoin('suppliers as sup','sup.supplierid','=','po.supplier_id')
                 ->whereRaw("po.supplier_id='$sup_id' AND po.is_cancelled=0 AND s.item_supplier_status IN (1)");

        if($searchItemStatus == 1 && !empty($search))
        {
            //OR (s.status='Cancelled' AND DATE_FORMAT(po.po_date,'%Y-%m-%d %H:%i:%s') BETWEEN '2024-12-01 00:00:00' AND DATE_FORMAT(NOW(),'%Y-%m-%d %H:%i:%s'))
            $query->whereRaw("( s.status IN ('In Process','Hold-On'))");

            $query->whereRaw("(po.opex_sku LIKE '%$search%' OR p.producttitle LIKE '%$search%' OR po.order_number LIKE '%$search%')");
        }
        else
        {   //OR (s.status='Cancelled' AND DATE_FORMAT(po.po_date,'%Y-%m-%d %H:%i:%s') BETWEEN '2024-12-01 00:00:00' AND DATE_FORMAT(NOW(),'%Y-%m-%d %H:%i:%s'))
            $query->whereRaw("( s.status IN ('In Process','Hold-On'))");
        }

        if($searchItemStatus == 1 && empty($search))
        {
            $query->limit(3);
        }


        $query->orderBy('s.urgent','DESC');

        $query->orderBy('po.opex_sku','ASC');

        if($searchItemStatus == 1)
        {
            $data = $query->get();

            return $data;
        }
        else
        {
        $data = $query->paginate(100);

        return response()->json($data);
        }
   }

   public function GetFBAorBulkOrders($searchStatus,$searchText,$ordType,$sup_id,$opex_sku,$pr_id)
   {
//       SELECT fi.id record_id,fi.pr_id,fi.supplier_id,fi.opex_sku,fi.assign_date,fi.given_qty,fi.received_qty,p.producttitle,p.productimage,CONCAT(s.firstname, '' ,s.lastname) sup_name FROM fba_pro_pr_items fi
// LEFT JOIN productitem p ON p.prodsku = fi.opex_sku
// LEFT JOIN suppliers s ON s.supplierid = fi.supplier_id
// LEFT JOIN fba_pro_purchase_request fppr ON fppr.id = fi.pr_id
// WHERE $stsm fi.supplier_id='$sup_id' AND fi.status=2 AND fppr.order_type='$order_type' AND fi.received_qty < fi.given_qty
        $dtp =0;

        if($ordType==1)
        {
            $dtp =3;
        }

        if($ordType==2)
        {
            $dtp =4;
        }

        $query = DB::table('fba_pro_pr_items as fi')
        ->selectRaw("($dtp) as dtype,CONCAT('PR #',fi.pr_id,' ',p.producttitle,' ',fi.opex_sku) text,fi.id as id,fi.id as record_id,fi.pr_id,CONCAT('Qty (',(fi.given_qty - fi.received_qty),')') as status,CONCAT('PR #',fi.pr_id) as order_number,fi.supplier_id,fi.opex_sku,fi.assign_date,ao.account as ref_number,fi.given_qty,fi.received_qty,p.producttitle,p.productimage,CONCAT(s.firstname, ' ' ,s.lastname) as sup_name");
        $query->leftJoin('productitem as p','p.prodsku','=','fi.opex_sku');
        $query->leftJoin('suppliers as s','s.supplierid','=','fi.supplier_id');
        $query->leftJoin('fba_pro_purchase_request as fppr','fppr.id','=','fi.pr_id');
        $query->leftJoin('opexpro_accounts as ao','ao.id','=','fppr.store');
        if($opex_sku > 0)
        {
            $query->where('fi.opex_sku',$opex_sku);
        }
        if($pr_id > 0 && $searchStatus==0)
        {
            $query->where('fi.pr_id',$pr_id);
        }
        $query->whereRaw("fi.supplier_id='$sup_id' AND fi.status=2 AND fppr.order_type='$ordType' AND fi.received_qty < fi.given_qty");

        $query->orderBy('fi.opex_sku','ASC');


        if($searchStatus == 1 && !empty($searchText))
        {
            $query->whereRaw("(fi.pr_id LIKE '%$searchText%' OR p.producttitle LIKE '%$searchText%' OR fi.opex_sku LIKE '%$searchText%')");
        }


        if($searchStatus == 1 && empty($searchText))
        {
            $query->limit(3);
        }


        if($searchStatus == 1)
        {
        $data = $query->get();

        return $data;
        }
        else{
        $data = $query->paginate(100);

        return response()->json($data);
        }
   }


   public function DropInItems(Request $request)
   {
         $qs = $request->query();

         $page = $qs['page'];

         $dropin_type = $qs['dropin_type'];

         $sup_id = $qs['sup_id'];



         if($dropin_type == 1 || $dropin_type == 2)
         {
            return $this->WarehouseOrders(0,'',$sup_id);
         }

         if($dropin_type == 3 || $dropin_type == 4)
         {
           $opex_sku = $qs['opex_sku'];

           $pr_id = $qs['pr_id'];

           $ordType = $dropin_type==3 ? 1 : 2;

           return $this->GetFBAorBulkOrders(0,'',$ordType,$sup_id,$opex_sku,$pr_id);
         }

   }

   public function dropInSearch(Request $request)
   {
       $qs = $request->query();

       $dropin_type = $qs['dropin_type'];

       $sup_id = $qs['sup_id'];

       $search = isset($qs['search']) ? $qs['search'] : '';

       if($dropin_type == 1 || $dropin_type == 2)
       {
        $items = $this->WarehouseOrders(1,$search,$sup_id);

        return response()->json(['results'=>$items]);
       }

        if($dropin_type == 3 || $dropin_type == 4)
       {

         $ordType = $dropin_type==3 ? 1 : 2;

         $items = $this->GetFBAorBulkOrders('1',$search,$ordType,$sup_id,0,0);

        return response()->json(['results'=>$items]);
       }
   }

   public function SaveDropIn(Request $req)
   {
        $hash_txt = Str::random(15);

        $hash_rnd = rand(1,100000000000);

        $hash_txt2 = Str::random(15);

        $main_hash = strtolower($hash_txt).$hash_rnd.$hash_txt2;

        $userid = Auth::user()->id;

        $userinfo = DB::table('st_users')->where('id',$userid)->first();

       if($req->drop_in_type==1 || $req->drop_in_type==2)
       {
       $postedData = $req->posted_data;
       $jsonDecode = json_decode($postedData,true);
    //   if($userid == 46)
    //   {
    //       print_r($jsonDecode);
    //       exit;
    //   }
       if(count($jsonDecode) > 0)
       {
           $orderTxtError ="";
           $validTxtOrder = "";
           $barcode_ids = [];


           foreach($jsonDecode as $k => $r)
           {

               $ch = DB::table('saleorders as s')
               ->selectRaw("gnp.id as gnp_id,gnp.supplier_id,s.saleorderid,s.order_number,s.status,s.is_sample_order,s.sample_order_for")
               ->leftJoin('generate_new_po as gnp','gnp.saleorderid','=','s.saleorderid')
               ->whereRaw("gnp.dropin_status=0 AND gnp.is_cancelled=0 AND s.order_number='".$r['Order']."' AND s.reference_no='".$r['Ref']."' AND s.status IN ('In Process','Hold-On','Cancelled')")
               ->get();

               if($ch->count() > 0)
               {


                  $rza = $ch->first();

                  $isSampleFreez = 0;

                  if($rza->is_sample_order == 1)
                  {
                      $isSampleFreez = 1;
                  }

                  $supIdNew = $rza->supplier_id;

                  $ItemSupplierStatus = 7;
                  $OrderStatus=$rza->status;
                //   if($supIdNew==1 && $OrderStatus=='In Process')
                //   {
                //       $ItemSupplierStatus = 2;
                //       $OrderStatus='Packing';
                //   }

                  DB::beginTransaction();

                      DB::table('generate_new_po')
                      ->where('order_number',$r['Order'])
                      ->where('ref_number',$r['Ref'])
                      ->update(['dropin_status'=>1,'dropin_date'=>date('Y-m-d H:i:s'),'dropin_by'=>$userid]);

                      DB::table('saleorders')->whereRaw("order_number='".$r['Order']."' AND reference_no='".$r['Ref']."'")->update([
                        //'status'=>$OrderStatus,
                        'item_supplier_status'=> $ItemSupplierStatus,
                        'is_sample_freez'=>$isSampleFreez
                        ]);

                  $typeLabel = ['','W','M','F','B'];



                  $Dsave = [
                      'gnp_id'=>$rza->gnp_id,
                      'saleorderid'=>$rza->saleorderid,
                      'dropin_type'=>$r['DropInType'],
                      'sup_id'=>$r['SupId'],
                      'sup_name'=>$r['SupName'],
                      'order_number'=>$r['Order'],
                      'ref_number'=>$r['Ref'],
                      'opex_sku'=>$r['Sku'],
                      'title'=>$r['Title'],
                      'created_by'=>$userid,
                      'created_at'=>date('Y-m-d H:i:s'),
                      'hash'=>$main_hash,
                      'is_sample_freez'=>$isSampleFreez

                      ];

                  $ch_ord_q = DB::table('new_drop_in_labels')->where('saleorderid',$rza->saleorderid)->where('order_number',$r['Order'])->where('status',1)->get();

                  if($ch_ord_q->count() > 0)
                  {

                      DB::table('new_drop_in_labels')
                      ->where('saleorderid',$rza->saleorderid)
                      ->where('order_number',$r['Order'])
                      ->update(['status'=>2,'cancellation_at'=>date('Y-m-d H:i:s'),'cancellled_by'=>'74'.$userid]);

                  }

                  $id = DB::table('new_drop_in_labels')->insertGetId($Dsave);

                  $barcode = $typeLabel[$r['DropInType']].str_pad($id, 4, '0', STR_PAD_LEFT);

                  DB::table('new_drop_in_labels')->where('id',$id)->update(['barcode'=>$barcode,'prefix'=>$typeLabel[$r['DropInType']],'status'=>1]);


                  $loginfo ="Item Dropped-In From <strong>".$r['SupName']."</strong> by <strong>".$userinfo->fullname."</strong> From Next Drop-In Page.";

                  if($supIdNew==1)
                  {
                   $loginfo ="Item Dropped-In From <strong>".$r['SupName']."</strong> by <strong>".$userinfo->fullname."</strong> From Next Drop-In Page. Order Moved to ".$OrderStatus." Stage.";
                  }


    				  $loginserted = DB::table('merchantorderlog')->insertGetId([
    				      	'logdate' => date('Y-m-d H:i:s'),
    						'logtimestamp' => time(),
    						'ordernumber' =>$r['Order'],
    						'orderdbid' =>$rza->saleorderid,
    						'logdetail' => $loginfo,
    						'loguser' => $userid
    				      ]);

				   DB::commit();

                  $validTxtOrder.=$r['Order'].", ";

                  $barcode_ids[]= $id;

                  if($rza->is_sample_order == 1)
                  {
                      $titleEmail = 'Sample Order #'.$r['Order'].' - '.$rza->sample_order_for.' Received In Warehouse '.date('d-m-Y');

                      $messageBody = '<p>Dear Team</p>';

                      $messageBody .= '<p>The Sample Order <strpmg>#'.$r['Order'].'</strong> Has been Received In Warehouse from Supplier.</p>';

                      $messageBody .= '<p><strong>'.$r['Title'].' | '.$r['Sku'].'</strong></p>';

                      $messageBody .= '<p>Thanks</p>';

                      $details = [
                        'title' => $titleEmail,
                        'body' => $messageBody,
                      ];

                    $subject = $titleEmail;

                    $recipients = ['bcc.mailnotifications@gmail.com','subhanshah.esire@gmail.com','abdulrehmanshahzad.esire@gmail.com','raja.esire@gmail.com'];

                    Mail::to($recipients)->send(new CustMail($details,$subject));
                      //Email is Here
                  }



               }
               else
               {
                   $orderTxtError.=$r['Order'].", ";
               }

           }

           return response()->json(['code'=>200,'order_error'=>$orderTxtError,'valid_orders'=>$validTxtOrder,'barcode_ids'=>$barcode_ids,'hash_txt'=>$main_hash]);

       }
       else
       {
           return response()->json(['code'=>404]);
       }
       }elseif($req->drop_in_type==3 || $req->drop_in_type==4)
       {

       $postedData = $req->posted_data;
       $jsonDecode = json_decode($postedData,true);

       if(count($jsonDecode) > 0)
       {
            $orderTxtError ="";

            $validTxtOrder = "";

            $barcode_ids = [];


            $main_hash = strtolower($hash_txt).$hash_rnd.$hash_txt2;

            foreach($jsonDecode as $k => $r)
            {
               $item_id = $r['Order'];

               $pr_id = $r['Ref'];

               $sup_id = $r['SupId'];

               $rec_qty = $r['ReceivedQty'];

               $opx_sku_txt = $r['Sku'];

               $check = DB::table('fba_pro_pr_items')->selectRaw("id,pr_id,supplier_id,opex_sku,given_qty,received_qty,order_type")->whereRaw("id='$item_id' AND pr_id='$pr_id' AND supplier_id='$sup_id' AND status=2")->get();

               if($check->count() > 0)
               {
                    $row = $check->first();

                    $given_qty = $row->given_qty;

                    $received_qty = $row->received_qty;

                    $remain = $given_qty - $received_qty;

                    $typex = $row->order_type;

                    $fba_item_id = $row->id;

                    $fba_pr_id = $row->pr_id;

                    $fba_sup_id = $row->supplier_id;



                    if($rec_qty <= $remain)
                    {


                        $recLogId = DB::table('fba_pro_qty_received_log')->insertGetId([

                            'pr_id'=>$row->pr_id,
                            'pr_item_id'=>$row->id,
                            'supplier_id'=>$row->supplier_id,
                            'sku'=>$row->opex_sku,
                            'received_qty'=>$rec_qty,
                            'received_by'=>$userid,
                            'received_date'=>date('Y-m-d H:i:s'),
                            'hashtxt'=>$main_hash,

                         ]);


                        $total = $received_qty + $rec_qty;


                        DB::table('fba_pro_pr_items')->whereRaw("id='$fba_item_id' AND pr_id='$fba_pr_id' AND supplier_id='$fba_sup_id'")->update([

                                'received_qty'=>$total,
                                'resp'=>json_encode(['last_qty'=>$received_qty,'updated_qty'=>$rec_qty,'total_sum'=>$total]),
                                'last_updated'=>date('Y-m-d H:i:s'),
                                'last_updated_by'=>$userid,
                                'last_hashtxt'=>$main_hash

                            ]);

                       /* if($typex == 2)
                        {

                            $ckInv = DB::table('fba_pro_inventory_bulk')->where('opex_sku',$row->opex_sku)->get();

                            if($ckInv->count() > 0)
                            {

                                $bulk_inv_row = $ckInv->first();

                                $bulk_inv_id = $bulk_inv_row->id;

                                $bulk_inv_sku = $bulk_inv_row->opex_sku;

                                $bulk_inv_qty = $bulk_inv_row->quantity + $rec_qty;

                                DB::table('fba_pro_inventory_bulk')->whereRaw("id='$bulk_inv_id' AND opex_sku='$bulk_inv_sku'")->update([
                                    'quantity'=>$bulk_inv_qty,
                                    'lasthashtxt'=>$main_hash
                                    ]);

                            }
                            else
                            {

                                DB::table('fba_pro_inventory_bulk')->insertGetId([
                                    'opex_sku'=>$row->opex_sku,
                                    'quantity'=>$rec_qty,
                                    'lasthashtxt'=>$main_hash
                                    ]);

                            }

                        }


                        if($sup_id != 1)
                        {

                        $sku_px = trim($row->opex_sku);

                        $qsx = DB::table('productitem')->select(['prodsku','qty'])->where('prodsku',$sku_px)->get();

                        if($qsx->count() > 0)
                        {
                            $rows = $qsx->first();

                            $current_qty = $rows->qty;

                            $remaining_qty = (int)$current_qty + (int)$rec_qty;

                            DB::table('productitem')->where('prodsku',$sku_px)->update([
                                'qty'=>$remaining_qty,
                                'hashtxt'=>$main_hash
                                ]);

                            $drop_in_type_n = $typex == 1 ? 2 : 3 ;

                            DB::table('in_hand_qty_log')->insertGetId([
                                'sup_id'=>$sup_id,
                                'drop_in_type'=>$drop_in_type_n,
                                'opex_sku'=>$row->opex_sku,
                                'action_type'=>1,
                                'qty_action'=>1,
                                'created_by'=>$userid,
                                'created_at'=>date('Y-m-d H:i:s'),
                                'hashtxt'=>$main_hash
                                ]);



                        }






                        }


                        if($sup_id == 1)
                        {

                            $sku_p = trim($row->opex_sku);

                            $qsx = DB::table('productitem')->select(['prodsku','qty'])->where('prodsku',$sku_p)->get();

                            if($qsx->count() > 0)
                            {
                                $rows = $qsx->first();

                                $current_qty = $rows->qty;

                                $remaining_qty = (int)$current_qty - (int)$rec_qty;

                                DB::table('productitem')->where('prodsku',$sku_p)->update([
                                'qty'=>$remaining_qty,
                                'hashtxt'=>$main_hash
                                ]);

                            }

                        }*/


                  $typeLabel = ['','W','M','F','B'];

                for($i=1;$i<=$rec_qty;$i++)
                {

                  $Dsave = [
                      'gnp_id'=>0,
                      'saleorderid'=>0,
                      'dropin_type'=>$r['DropInType'],
                      'sup_id'=>$r['SupId'],
                      'sup_name'=>$r['SupName'],
                      'order_number'=>$r['Order'],
                      'ref_number'=>$r['Ref'],
                      'opex_sku'=>$r['Sku'],
                      'title'=>$r['Title'],
                      'created_by'=>$userid,
                      'created_at'=>date('Y-m-d H:i:s'),
                      'hash'=>$main_hash,
                      'pr_id'=>$pr_id,
                      'pr_item_id'=>$item_id,
                      'prev_qty'=>$received_qty,
                      'added_qty'=>$rec_qty,
                      'sum_qty'=>$total,
                      'received_log_id'=>$recLogId
                      ];



                  $id = DB::table('new_drop_in_labels')->insertGetId($Dsave);

                  $barcode = $typeLabel[$r['DropInType']].str_pad($id, 4, '0', STR_PAD_LEFT);

                  DB::table('new_drop_in_labels')->where('id',$id)->update(['barcode'=>$barcode,'prefix'=>$typeLabel[$r['DropInType']],'status'=>1]);

                }

                  $validTxtOrder.="PR ID #".$pr_id." SKU #".$opx_sku_txt." Qty #".$rec_qty.", ";

                  $barcode_ids[] = $id;

               }
               else
               {
                    $orderTxtError.="PR ID #".$pr_id." SKU #".$opx_sku_txt." Qty #".$rec_qty.", ";
               }

            }else
            {
                $orderTxtError.="PR ID #".$pr_id." SKU #".$opx_sku_txt." Qty #".$rec_qty.", ";
                //not found in db
            }
       }    //foreach end

             return response()->json(['code'=>200,'order_error'=>$orderTxtError,'valid_orders'=>$validTxtOrder,'barcode_ids'=>$barcode_ids,'hash_txt'=>$main_hash]);


       }
       else
       {
           return response()->json(['code'=>404]);
       }

    //   echo "<pre>";
    //   print_r($jsonDecode);
    //   echo "</pre>";
       }


   }

   public function BarcodeGenerate($hash)
   {
       $query = DB::table('new_drop_in_labels')->selectRaw("*")->where('hash',$hash)->where('status',1)->get();


       if($query->count() > 0)
       {
          $html='<style>
          .single-label-body{
                 background-color: bisque;
                  min-height: 90vh;
                  width: 378px;
                  font-size: 14px;
          }
          .main-div{
              margin:0px !important;
              padding:0px !important;
          }
          .label-header{
              text-align:center;
              margin-left: 55px;
              margin-right: 55px;
              padding-top:15px;
          }
          .prefix{
                  font-family: math;
                  font-weight: 600;
          }
          .opex-sku{
                 font-family: math;
                 font-weight: 600;
                 width: 68%;
                 text-align: left;
          }
          .dcodes {
              display:flex;
              justify-content: space-between;

          }
          .barcode-text{
               font-family: math;
               font-weight: 600;
               text-align:center;
          }
          </style><div class="main-div">';
          foreach($query as $r)
          {
            $code = $r->barcode;


            if($r->dropin_type==1 || $r->dropin_type==2)
            {
                //$barcode = DNS1DFacade::getBarcodePNG($code, 'C128B',2,60);
                $barcode = DNS1DFacade::getBarcodePNG($code, 'C39',2,60);
            }
            else
            {
                //$barcode = DNS1DFacade::getBarcodePNG($code, 'C39', 2, 80);
                //$barcode = DNS1DFacade::getBarcodePNG($code, 'C128', 2, 40);
                $barcode = DNS1DFacade::getBarcodePNG($code, 'C39',2,35);
            }
            $prefix_txt = "";

            if($r->dropin_type==1)
            {
               $prefix_txt = "SO";
            }

            if($r->dropin_type==2)
            {
               $prefix_txt = "SO";
            }

            if($r->dropin_type==3)
            {
               $prefix_txt = "FBA";
            }

            if($r->dropin_type==4)
            {
               $prefix_txt = "BULK";
            }

            $lbl_ord = $r->order_number;

            $refno = explode('-',$r->ref_number);
            $exp =  $refno[0];

            $country = "";

            $SampleOrderSettings = "";

            if ($exp == 'Sam') {

                 $saleorderItem = DB::table('saleorders')->select('sample_order_for')->where('order_number',$lbl_ord)->first();

                 $SampleOrderSettings = "<span style='font-size:10px;'>$saleorderItem->sample_order_for</span>";
            }

            if($r->pr_id==240)
            {
                $SampleOrderSettings="Safety Stock";
            }

            if($r->dropin_type==3 || $r->dropin_type==4)
            {


                 if(!empty($r->ref_number))
                 {

                     $fbacode = DB::table('fba_pro_purchase_request AS fppr')
    ->leftJoin('opexpro_accounts AS oa', 'oa.id', '=', 'fppr.store')
    ->where('fppr.id', '=', trim($r->ref_number))
    ->select('oa.short_code')
    ->first();

                     $lbl_ord = $fbacode->short_code.":".$r->ref_number;


                 }

            }



            if($r->dropin_type==1 || $r->dropin_type==2)
            {
                if(!empty($r->saleorderid))
                {
                    $qsale = DB::table('saleorders')->select(['country','order_number','web_link','is_prime_delivery'])->where('saleorderid',trim($r->saleorderid))->first();

                    $prime ='';

                    if($qsale->is_prime_delivery == 1){
                        $prime = 'Prime';
                    }elseif($qsale->is_prime_delivery == 3){
                        $prime = 'FAST';
                    }

                    $country = "<span style='font-size:10px;'>".$qsale->country." : ".$prime."</span>";

                    if($qsale->web_link =="Amazon Decrum" || $qsale->web_link =="Decrum Website" || $qsale->web_link =="Fan Jackets")
    			    {
    			        $lbl_ord = "DE: ".$lbl_ord;
    			    }
    			    if($qsale->web_link =="Amazon Fjackets" || $qsale->web_link =="F Jackets" || $qsale->web_link =="IendGame")
    			    {
    			        $lbl_ord = "FJ: ".$lbl_ord;
    			    }
    			    if($qsale->web_link =="Angel" || $qsale->web_link =="Shahzad A/C" || $qsale->web_link =="Amazon AxFashions")
    			    {
    			        $lbl_ord = "AB: ".$lbl_ord;
    			    }
                }
            }

            $NextSKUx = trim($r->opex_sku);
            $ptitle=$r->title;

            // $NpCheck = DB::table('productitem')->where('prodsku',$NextSKUx)->where('new_pattern',1)->get();
            $NpCheck = DB::table('productitem')->where('prodsku',$NextSKUx)->get();

            if($NpCheck->count() > 0)
            {
                $rrr=$NpCheck->first();
                if($rrr->new_pattern==1){
                    $NextSKUx = "NP-".trim($r->opex_sku);
                }

                $ptitle=$rrr->producttitle;



            }

            $ptitle = $ptitle == 'null' ? $NextSKUx : $ptitle;

            $html.='<div class="single-label-body">';

            $html.='<div class="label-header">
                <div class="dcodes">
                    <div class="opex-sku">'.$ptitle.'</div>
                    <div class="prefix"><strong>'.$prefix_txt.'</strong></div>
                </div>

                <div class="barcode">
                    	<img src="data:image/png;base64,' .$barcode. '" style="" >
                    	<div class="barcode-text">'.$code.'</div>
                </div>

                <div class="dcodes">
                    <div class="opex-sku">'.$NextSKUx.'</div>
                    <div class="prefix">'.$lbl_ord.'</div>
                </div>
                <div class="dcodes">
                    <div class="opex-sku">'.strtoupper(substr($r->sup_name, 0, 4)).'</div>
                    <div class="prefix">'.date('d-m-Y').'</div>
                </div>
                 <div class="dcodes">
                    <div class="opex-sku">'.$country.'</div>
                    <div class="prefix">'.$SampleOrderSettings.'</div>
                </div>
            </div>';











            $html.='</div>';

          }

            $html.='</div>';

          echo $html;
          echo "<script type='text/javascript'>


         var beforePrint = function() {
        console.log('Functionality to run before printing.');

    };
    var afterPrint = function() {
        console.log('Functionality to run after printing');
			window.close();
    };

    if (window.matchMedia) {
        var mediaQueryList = window.matchMedia('print');
        mediaQueryList.addListener(function(mql) {
            if (mql.matches) {
                beforePrint();
            } else {
                afterPrint();
            }
        });
    }

    window.onbeforeprint = beforePrint;
    window.onafterprint = afterPrint;

     window.print();
        </script>
        ";
       }
       else
       {
           return response()->json(['code'=>404,'msg'=>'labels not found.']);
       }
   }

   public function BarcodeGenerateById($id,$prefix)
   {


       $query = DB::table('new_drop_in_labels');

       $query->select("*");

       if($prefix=="W" ||  $prefix=="M")
       {
        $query->where('id',$id);
       }

       if($prefix=="F" ||  $prefix=="B")
       {
       $query->where('received_log_id',$id);
       }

       $query->where('status',1);

       $results = $query->get();



       if($results->count() > 0)
       {
          $html='<style>
          .single-label-body{
                 background-color: bisque;
                  min-height: 90vh;
                  width: 378px;
                  font-size: 14px;
          }
          .main-div{
              margin:0px !important;
              padding:0px !important;
          }
          .label-header{
              text-align:center;
              margin-left: 55px;
              margin-right: 55px;
              padding-top:15px;
          }
          .prefix{
                  font-family: math;
                  font-weight: 600;
          }
          .opex-sku{
                 font-family: math;
                 font-weight: 600;
                 width: 68%;
                 text-align: left;
          }
          .dcodes {
              display:flex;
              justify-content: space-between;

          }
          .barcode-text{
               font-family: math;
               font-weight: 600;
               text-align:center;
          }
          </style><div class="main-div">';
          foreach($results as $r)
          {
            $code = $r->barcode;

            //ye setting sab jagan laga do abdul rehman jr. merchant order single label with id
            //$barcode = DNS1DFacade::getBarcodePNG($code, 'C128',2,60);
            //  $barcode = DNS1DFacade::getBarcodePNG($code, 'C39', 2, 80);
            //$barcode = DNS1DFacade::getBarcodePNG($code, 'C128',2,60);

            if($r->dropin_type==1 || $r->dropin_type==2)
            {
                //$barcode = DNS1DFacade::getBarcodePNG($code, 'C128B',2,60);
                $barcode = DNS1DFacade::getBarcodePNG($code, 'C39',2,60);
            }
            else
            {
                //$barcode = DNS1DFacade::getBarcodePNG($code, 'C39', 2, 80);
                //$barcode = DNS1DFacade::getBarcodePNG($code, 'C128', 2, 40);
                $barcode = DNS1DFacade::getBarcodePNG($code, 'C39',2,35);
            }

            $prefix_txt = "";

            if($r->dropin_type==1)
            {
               $prefix_txt = "SO";
            }

            if($r->dropin_type==2)
            {
               $prefix_txt = "SO";
            }

            if($r->dropin_type==3)
            {
               $prefix_txt = "FBA";
            }

            if($r->dropin_type==4)
            {
               $prefix_txt = "BULK";
            }

             $lbl_ord = $r->order_number;

            $country = "";

            $SampleOrderSettings = "";

            if (strpos($lbl_ord, "-s") !== false) {

                 $saleorderItem = DB::table('saleorders')->select('sample_order_for')->where('order_number',$lbl_ord)->first();

                 $SampleOrderSettings = "<span style='font-size:10px;'>'.$saleorderItem->sample_order_for.'</span>";
            }

            if($r->pr_id==240)
            {
                $SampleOrderSettings="Safety Stock";
            }

            if($r->dropin_type==3 || $r->dropin_type==4)
            {


                 if(!empty($r->ref_number))
                 {

                     $fbacode = DB::table('fba_pro_purchase_request AS fppr')
    ->leftJoin('opexpro_accounts AS oa', 'oa.id', '=', 'fppr.store')
    ->where('fppr.id', '=', trim($r->ref_number))
    ->select('oa.short_code')
    ->first();

                     $lbl_ord = $fbacode->short_code.":".$r->ref_number;


                 }

            }



            if($r->dropin_type==1 || $r->dropin_type==2)
            {
                if(!empty($r->saleorderid))
                {
                    $qsale = DB::table('saleorders')->select(['country','order_number','web_link','is_prime_delivery'])->where('saleorderid',trim($r->saleorderid))->first();

                    $prime ='';

                    if($qsale->is_prime_delivery == 1){
                        $prime = 'Prime';
                    }elseif($qsale->is_prime_delivery == 3){
                        $prime = 'FAST';
                    }

                    $country = "<span style='font-size:10px;'>".$qsale->country." : ".$prime."</span>";


                    if($qsale->web_link =="Amazon Decrum" || $qsale->web_link =="Decrum Website" || $qsale->web_link =="Fan Jackets")
    			    {
    			        $lbl_ord = "DE: ".$lbl_ord;
    			    }
    			    if($qsale->web_link =="Amazon Fjackets" || $qsale->web_link =="F Jackets" || $qsale->web_link =="IendGame")
    			    {
    			        $lbl_ord = "FJ: ".$lbl_ord;
    			    }
    			    if($qsale->web_link =="Angel" || $qsale->web_link =="Shahzad A/C" || $qsale->web_link =="Amazon AxFashions")
    			    {
    			        $lbl_ord = "AB: ".$lbl_ord;
    			    }
                }
            }

            $NextSKUx = trim($r->opex_sku);
            $ptitle = $r->title;

            // $NpCheck = DB::table('productitem')->where('prodsku',$NextSKUx)->where('new_pattern',1)->get();
            $NpCheck = DB::table('productitem')->where('prodsku',$NextSKUx)->get();

            if($NpCheck->count() > 0)
            {
                // $NextSKUx = "NP-".trim($r->opex_sku);

                $rrr=$NpCheck->first();
                if($rrr->new_pattern==1){
                    $NextSKUx = "NP-".trim($r->opex_sku);
                }

                $ptitle=$rrr->producttitle;

            }


            $html.='<div class="single-label-body">';

            $html.='<div class="label-header">
                <div class="dcodes">
                    <div class="opex-sku">'.$ptitle.'</div>
                    <div class="prefix"><strong>'.$prefix_txt.'</strong></div>
                </div>

                <div class="barcode">
                    	<img src="data:image/png;base64,' .$barcode. '" style="" >
                    	<div class="barcode-text">'.$code.'</div>
                </div>

                <div class="dcodes">
                    <div class="opex-sku">'.$NextSKUx.'</div>
                    <div class="prefix">'.$lbl_ord.'</div>
                </div>
                <div class="dcodes">
                    <div class="opex-sku">'.strtoupper(substr($r->sup_name, 0, 4)).'</div>
                    <div class="prefix">'.date('d-m-Y').'</div>
                </div>
                 <div class="dcodes">
                    <div class="opex-sku">'.$country.'</div>
                    <div class="prefix">'.$SampleOrderSettings.'</div>
                </div>
            </div>';











            $html.='</div>';

          }

            $html.='</div>';

          echo $html;
       }
       else
       {
           return response()->json(['code'=>404,'msg'=>'labels not found.']);
       }
   }

   public function BarcodeGenerateByIdForAlteration($barcode)
   {

       $query = DB::table('new_drop_in_labels');

       $query->select("*");

       $query->where('barcode',$barcode);

    //   $query->where('status',1);

       $results = $query->get();

       if($results->count() > 0)
       {
          $html='<style>
          .single-label-body{
                 background-color: bisque;
                  min-height: 90vh;
                  width: 378px;
                  font-size: 14px;
          }
          .main-div{
              margin:0px !important;
              padding:0px !important;
          }
          .label-header{
              text-align:center;
              margin-left: 55px;
              margin-right: 55px;
              padding-top:15px;
          }
          .prefix{
                  font-family: math;
                  font-weight: 600;
          }
          .opex-sku{
                 font-family: math;
                 font-weight: 600;
                 width: 68%;
                 text-align: left;
          }
          .dcodes {
              display:flex;
              justify-content: space-between;

          }
          .barcode-text{
               font-family: math;
               font-weight: 600;
               text-align:center;
          }
          </style><div class="main-div">';
          foreach($results as $r)
          {
            $code = $r->barcode;


            // $barcode =DNS1DFacade::getBarcodePNG($code, 'C128',2,60);
            if($r->dropin_type==1 || $r->dropin_type==2)
            {
                //$barcode = DNS1DFacade::getBarcodePNG($code, 'C128B',2,60);
                $barcode = DNS1DFacade::getBarcodePNG($code, 'C39',2,60);
            }
            else
            {
                //$barcode = DNS1DFacade::getBarcodePNG($code, 'C39', 2, 80);
                //$barcode = DNS1DFacade::getBarcodePNG($code, 'C128', 2, 40);
                $barcode = DNS1DFacade::getBarcodePNG($code, 'C39',2,35);
            }

            $prefix_txt = "";

            if($r->dropin_type==1)
            {
               $prefix_txt = "WH";
            }

            if($r->dropin_type==2)
            {
               $prefix_txt = "SO";
            }

            if($r->dropin_type==3)
            {
               $prefix_txt = "FBA";
            }

            if($r->dropin_type==4)
            {
               $prefix_txt = "BULK";
            }

             $lbl_ord = $r->order_number;

            $country = "";

            $SampleOrderSettings = "";

            if (strpos($lbl_ord, "-s") !== false) {
                 $SampleOrderSettings = "<span style='font-size:10px;'>SAMPLE ORDER</span>";
            }

            if($r->pr_id==240)
            {
                $SampleOrderSettings="Safety Stock";
            }

            if($r->dropin_type==3 || $r->dropin_type==4)
            {


                 if(!empty($r->ref_number))
                 {

                     $fbacode = DB::table('fba_pro_purchase_request AS fppr')
    ->leftJoin('opexpro_accounts AS oa', 'oa.id', '=', 'fppr.store')
    ->where('fppr.id', '=', trim($r->ref_number))
    ->select('oa.short_code')
    ->first();

                     $lbl_ord = $fbacode->short_code.":".$r->ref_number;


                 }

            }



            if($r->dropin_type==1 || $r->dropin_type==2)
            {
                if(!empty($r->saleorderid))
                {
                    $qsale = DB::table('saleorders')->select(['country','order_number','web_link'])->where('saleorderid',trim($r->saleorderid))->first();

                    $country = "<span style='font-size:10px;'>".$qsale->country."</span>";

                    if($qsale->web_link =="Amazon Decrum" || $qsale->web_link =="Decrum Website" || $qsale->web_link =="Fan Jackets")
    			    {
    			        $lbl_ord = "DE: ".$lbl_ord;
    			    }
    			    if($qsale->web_link =="Amazon Fjackets" || $qsale->web_link =="F Jackets" || $qsale->web_link =="IendGame")
    			    {
    			        $lbl_ord = "FJ: ".$lbl_ord;
    			    }
    			    if($qsale->web_link =="Angel" || $qsale->web_link =="Shahzad A/C" || $qsale->web_link =="Amazon AxFashions")
    			    {
    			        $lbl_ord = "AB: ".$lbl_ord;
    			    }
                }
            }

            $NextSKUx = trim($r->opex_sku);
            $ptitle = $r->title;

             $NpCheck = DB::table('productitem')->where('prodsku',$NextSKUx)->where('new_pattern',1)->get();

            if($NpCheck->count() > 0)
            {
                // $NextSKUx = "NP-".trim($r->opex_sku);
                $rrr=$NpCheck->first();
                if($rrr->new_pattern==1){
                    $NextSKUx = "NP-".trim($r->opex_sku);
                }

                $ptitle=$rrr->producttitle;
            }


            $html.='<div class="single-label-body">';

            $html.='<div class="label-header">
                <div class="dcodes">
                    <div class="opex-sku">'.$ptitle.'</div>
                    <div class="prefix"><strong>'.$prefix_txt.'</strong></div>
                </div>

                <div class="barcode">
                    	<img src="data:image/png;base64,' .$barcode. '" style="" >
                    	<div class="barcode-text">'.$code.'</div>
                </div>

                <div class="dcodes">
                    <div class="opex-sku">'.$NextSKUx.'</div>
                    <div class="prefix">'.$lbl_ord.'</div>
                </div>
                <div class="dcodes">
                    <div class="opex-sku">'.strtoupper(substr($r->sup_name, 0, 4)).'</div>
                    <div class="prefix">'.date('d-m-Y').'</div>
                </div>
                 <div class="dcodes">
                    <div class="opex-sku">'.$country.'</div>
                    <div class="prefix">'.$SampleOrderSettings.'</div>
                </div>
            </div>';











            $html.='</div>';

          }

            $html.='</div>';

          echo $html;
       }
       else
       {
           return response()->json(['code'=>404,'msg'=>'labels not found.']);
       }
   }


   public function OrderReleaseHubBarcode($id)
   {
      $results = DB::table('order_release_from_hub as orh')
    ->select('orh.saleorderid', 'orh.barcode', 'orh.order_number', 's.web_link','nl.opex_sku', 's.country', 'nl.sup_id','nl.title', 'sup.suppliercode')
    ->leftJoin('saleorders as s', 's.saleorderid', '=', 'orh.saleorderid')
    ->leftJoin('new_drop_in_labels as nl', 'nl.barcode', '=', 'orh.barcode')
    ->leftJoin('suppliers as sup', 'sup.supplierid', '=', 'nl.sup_id')
    ->where('orh.id', $id)
    ->get();


      if($results->count() > 0)
      {

          $r = $results->first();


          $code = $r->barcode;

          //$barcode = DNS1DFacade::getBarcodePNG($code, 'C39', 2, 80);
          $barcode = DNS1DFacade::getBarcodePNG($code, 'C128', 2, 40);

          $html='<style>
          .single-label-body{
                 background-color: bisque;
                  min-height: 90vh;
                  width: 378px;
                  font-size: 14px;
          }
          .main-div{
              margin:0px !important;
              padding:0px !important;
          }
          .label-header{
              text-align:center;
              margin-left: 55px;
              margin-right: 55px;
              padding-top:15px;
          }
          .prefix{
                  font-family: math;
                  font-weight: 600;
          }
          .opex-sku{
                 font-family: math;
                 font-weight: 600;
                 width: 68%;
                 text-align: left;
          }
          .dcodes {
              display:flex;
              justify-content: space-between;

          }
          .barcode-text{
              font-family: math;
              font-weight: 600;
              text-align:center;
          }
          </style><div class="main-div">';


            $html.='<div class="single-label-body">';

            $html.='<div class="label-header">
                <div class="dcodes">
                    <div class="opex-sku">'.$r->title.'</div>
                    <div class="prefix"><strong>SO</strong></div>
                </div>

                <div class="barcode">
                    	<img src="data:image/png;base64,' .$barcode. '" style="" >
                    	<div class="barcode-text">'.$code.'</div>
                </div>

                <div class="dcodes">
                    <div class="opex-sku">'.$r->opex_sku.'</div>
                    <div class="prefix">'.$r->web_link.'</div>
                </div>
                <div class="dcodes">
                    <div class="opex-sku">HUB : '.$r->order_number.'</div>
                    <div class="prefix">'.date('d-m-Y').'</div>
                </div>
                 <div class="dcodes">
                    <div class="opex-sku">'.$r->country.'</div>
                    <div class="prefix">'.$r->suppliercode.'</div>
                </div>
            </div>';











            $html.='</div>';



            $html.='</div>';

          echo $html;
      }
      else
      {
          return response()->json(['code'=>404,'msg'=>'labels not found.']);
      }
   }

   public function DropInGatePass()
   {
       $data['sup_list'] = DB::table('suppliers')->where('is_active','=',1)
                            ->where('supptype','=',1)->get();

       return view('supplychain.dgatepass',$data);
   }

   public function DropInGatePassBoth()
   {
       $data['sup_list'] = DB::table('suppliers')->where('is_active','=',1)
                            ->where('supptype','=',1)->get();

       return view('supplychain.dgatepassboth',$data);
   }


    public function DropInDatatableBoth(Request $r)
   {
        $sup_id = $r->sup_id;
        $startDate = $r->start_date;
        $endDate = $r->end_date;

        $ArrPkrPrice = [];

        $priceQuery = DB::table('productitem')->select(['prodsku','pkr_price'])->where('pkr_price','>',0)->get();

        if($priceQuery->count() > 0)
        {
            foreach($priceQuery as $r)
            {
                $ArrPkrPrice[trim($r->prodsku)]=$r->pkr_price;
            }
        }

        $query = DB::table('new_drop_in_labels')
        ->select(['producttitle','id','saleorderid','prefix','received_log_id','hash','barcode','added_qty','order_number','opex_sku','title','pr_id','pr_item_id','is_sample_freez',DB::raw("COUNT(id) total,DATE_FORMAT(created_at,'%Y-%m-%d') AS c_at")])
        ->leftJoin('productitem as p', 'p.prodsku', '=', 'new_drop_in_labels.opex_sku') //added by sufian 09-03-24
        ->where('sup_id',$sup_id)
        ->where('status','!=',3)
        ->where('is_manual','=',0)
        ->where('prefix','!=','H')
        ->whereBetween(DB::raw("DATE_FORMAT(created_at,'%Y-%m-%d')"), [$startDate, $endDate])
        ->groupBy('dropin_type')
        ->groupBy('opex_sku')
        ->groupBy('order_number')
        ->groupBy('received_log_id')
        ->get();

        $arr = [];

        if($query->count() > 0)
        {
            foreach($query as $q)
            {
                $arr[] = [

                    'c_at'=>$q->c_at,
                    'prefix'=>$q->prefix,
                    'barcode'=>$q->barcode,
                    'order_number'=>$q->order_number,
                    'pr_id'=>$q->pr_id,
                    'pr_item_id'=>$q->pr_item_id,
                    'received_log_id'=>$q->received_log_id,
                    'opex_sku'=>$q->opex_sku,
                    'title' => $q->title,
                    'total'=>$q->total,
                    'hash'=>$q->hash,
                    'id'=>$q->id,
                    'saleorderid'=>$q->saleorderid,
                    'producttitle'=>$q->producttitle
                    ];
            }
        }



        $qtwo = DB::table('hub_wh_receiving_log as wl')
        ->select('wl.opex_sku', 'wl.title','wl.sup_id', 'wl.barcode','wl.sup_name','wl.id','wl.dropin_label_id', DB::raw("DATE_FORMAT(wl.received_date, '%Y-%m-%d') as rec_date"), DB::raw("'1' as total,'Hub' as typetxt, nl.received_log_id,nl.pr_id,nl.pr_item_id,nl.prefix,nl.saleorderid as cancelled_saleorderid,nl.order_number cancelled_order_number"))
        ->leftJoin('new_drop_in_labels as nl','nl.id','=','wl.dropin_label_id')
        ->where('wl.sup_id', $sup_id)
        ->whereBetween(DB::raw("DATE_FORMAT(received_date, '%Y-%m-%d')"), [$startDate, $endDate])
        ->where('wl.is_reverted',0)
        ->whereRaw("wl.status IN (1,2)")
        ->get();


        $arr_qtwo = [];

        if($qtwo->count() > 0)
        {
            foreach($qtwo as $q)
            {
                $ord = $q->pr_id."-".$q->pr_item_id."-".$q->received_log_id;

                $arr_qtwo[] = [

                    'c_at'=>$q->rec_date,
                    'prefix'=>$q->prefix,
                    'barcode'=>$q->barcode,
                    'order_number'=>$q->received_log_id,
                    'pr_id'=>$q->pr_id,
                    'pr_item_id'=>$q->pr_item_id,
                    'received_log_id'=>$q->received_log_id,
                    'opex_sku'=>$q->opex_sku,
                    'title' => $q->title,
                    'total'=>1,
                    'hash'=>'',
                    'id'=>$q->id,
                    'saleorderid'=>'',
                    'producttitle'=>$q->title,
                    'cancelled_saleorderid'=>$q->cancelled_saleorderid,
                    'cancelled_order_number'=>$q->cancelled_order_number
                    ];
            }
        }

        $merg = array_merge($arr,$arr_qtwo);

         return Datatables::of($merg)
                     ->addIndexColumn()
                     ->addColumn('orderType', function ($query) {
                         $type = "NF";
                         $pref = $query['prefix'];
                         if($pref == "B")
                         {
                             $type = "BULK";
                         }
                          if($pref == "F")
                         {
                             $type = "FBA";
                         }
                          if($pref == "M")
                         {
                             $type = "Merchant";
                         }
                          if($pref == "W")
                         {
                             $type = "Warehouse";
                         }

                         if($pref == "H")
                         {
                             $type = "Hub Warehouse";
                         }

                         return $type;

                     })
                     ->addColumn('serialId', function ($query) {

                         $serial = $query['saleorderid'];

                         if($query['prefix'] == "F" || $query['prefix'] == "B" || $query['prefix'] == "H")
                         {
                              $serial = $query['received_log_id'];
                         }

                         if(empty($serial) && $query['pr_id'] == 240)
                         {
                             $serial = "CPO:".$query['cancelled_saleorderid'];
                         }

                         return $serial;
                     })
                     ->addColumn('ordNum', function ($query) {

                         $ord = $query['order_number'];

                         if($query['prefix'] == "F" || $query['prefix'] == "B" || $query['prefix'] == "H")
                         {
                             $ord = $query['pr_id']."-".$query['pr_item_id']."-".$query['received_log_id'];
                         }

                          if($query['pr_id'] == 240)
                         {
                             $ord = "CPO:".$query['cancelled_order_number'];
                         }

                         return $ord;

                     })
                     ->addColumn('OpexSku', function ($query) {
                         $length = strlen($query['opex_sku']) - 1;
                         $opex_sku = substr($query['opex_sku'],0,$length);
                         return $opex_sku;
                     })
                     ->addColumn('size', function ($query) {
                        $s = "NF";
                        if(strrpos($query['title'],"|") > 0)
                        {
                            $e = explode("|",$query['title']);

                            $s = $e[1];
                        }
                        return $s;
                     })
                     ->addColumn('qtyReceived', function ($query) {

                         $qty = 1;

                         if($query['prefix'] == "F" || $query['prefix'] == "B")
                         {
                              $qty = $query['total'];
                            //$qty = 1;
                         }

                         return '<span class="qty-calc" data-qty="'.$qty.'" data-profix="'.$query['prefix'].'">'.$qty.'</span>';

                     })


                    //  ->addColumn('action', function ($query) {
                    //      $delhtml = '';
                    //      $userList=[13,114,89];
                    //      if(in_array(auth()->user()->id,$userList)){
                    //          if($query['prefix'] != 'H'){
                    //           $delhtml = '<a href="javascript:;" data-hash="'.$query['hash'].'" data-id="'.$query['id'].'" class="btn btn-danger btn-sm btn-remove-from-gatepass"><i class="fa fa-trash"></i></a>';
                    //          }

                    //          }


                    //      if($query['is_sample_freez'] == 1 && Auth::user()->id!=1)
                    //      {
                    //          return '<span class="badge bg-danger">Sample Freez</span>';
                    //      }else{
                    //      return '<a href="javascript:;" data-hash="'.$query->hash.'" data-id="'.$query->id.'" data-prefix="'.$query->prefix.'" data-received-id="'.$query->received_log_id.'" class="btn btn-info btn-sm btn-print-from-gatepass"><i class="fa fa-print"></i></a>
                    //      '.$delhtml.'
                    //      ';
                    //      }
                    //  })
                    //  ->addColumn('UnitCost', function ($query) use($ArrPkrPrice) {
                    //      $cost = 0;
                    //      if(isset($ArrPkrPrice[$query->opex_sku]))
                    //      {
                    //         $cost = $ArrPkrPrice[$query->opex_sku];
                    //      }
                    //      return $cost;
                    //  })
                    //  ->addColumn('TotalCost', function ($query) use($ArrPkrPrice) {
                    //      $qty = 1;

                    //      if($query->prefix == "F" || $query->prefix == "B")
                    //      {
                    //           $qty = $query->total;

                    //      }

                    //      $cost = 0;

                    //      if(isset($ArrPkrPrice[$query->opex_sku]))
                    //      {
                    //         $cost = (int)$ArrPkrPrice[$query->opex_sku] * (int)$qty;
                    //      }

                    //      return $cost;
                    //  })
                    ->rawColumns(['action','qtyReceived'])
                    ->make(true);
   }

   public function dropInLabelExport($start_date,$end_date,$sup_id){
        $filename = 'Drop_In_Label'.time().'.csv';
        (new DropInLabelExport($start_date, $end_date,$sup_id))->store($filename,'excel_uploads');
        $url = $filename;
        return response()->json([
            'status' => true,
            'data' => $url
        ]);
    }

    public function downloadLabelcsv($file)
    {
        $file_path = public_path('downloads/export/'.$file);
        return response()->download($file_path);
    }

    public function ExportGatePass($start,$end,$supid)
    {

        $ArrPkrPrice = [];

        $getOrdStatus = $this->getOrderStatus();

        // $priceQuery = DB::table('productitem')->select(['prodsku','pkr_price'])->where('pkr_price','>',0)->get();
        $priceQuery = DB::table('mainproduct')
    ->select('masterprodsku', 'pkr_price')
    ->where('usd_price', '>', 0)
    ->where('pkr_price', '>', 0)
    ->get();

        if($priceQuery->count() > 0)
        {
            foreach($priceQuery as $r)
            {
                $ArrPkrPrice[trim($r->masterprodsku)]=$r->pkr_price;
            }
        }


         $query = DB::table('new_drop_in_labels')
        ->select(['id','saleorderid','prefix','received_log_id','hash','barcode','added_qty','order_number','opex_sku','title','pr_id','pr_item_id',DB::raw("COUNT(id) total,DATE_FORMAT(created_at,'%d-%m-%Y') AS c_at")])
        ->where('sup_id',$supid)
         ->where('status','!=',3)
        ->where('is_manual','=',0)
        ->where('prefix','!=','H')
        ->whereBetween(DB::raw("DATE_FORMAT(created_at,'%Y-%m-%d')"), [$start, $end])
        ->groupBy('dropin_type')
        ->groupBy('opex_sku')
        ->groupBy('order_number')
        ->groupBy('received_log_id')
        ->get();
        $arr = [];
        $columns = ['Date','Barcode','Sr#','Serial No.','Order No.','Order Status','SKU','Product','Size','Qty','Unit Cost','Total'];
        $total_merchant = 0;
        $total_fba =0;
        $total_bulk = 0;

        foreach($query as $r)
        {
            $tagstatus = isset($getOrdStatus[$r->saleorderid]) ? strip_tags($getOrdStatus[$r->saleorderid]) : '';

            $adQty = empty($r->added_qty) ? 1 : $r->total;
            $cost = 0;
            $total=0;
            $type = "NF";

            if($r->prefix == "B")
            {
                $type = "BULK";

                $total_bulk += $adQty;
            }
            if($r->prefix == "F")
            {
                $type = "FBA";

                $total_fba += $adQty;
            }
            if($r->prefix == "M")
            {
                $type = "Merchant";

                $total_merchant += $adQty;
            }
            if($r->prefix == "W")
            {
                $type = "Warehouse";

                $total_merchant += $adQty;
            }

            $ord = $r->order_number;

            $seral = $r->saleorderid;

            if($r->prefix == "F" || $r->prefix == "B")
            {
                $ord = $r->pr_id."-".$r->pr_item_id."-".$r->received_log_id;

                $seral = $r->received_log_id;
            }

            $length = strlen($r->opex_sku) - 1;
            $opex_sku = substr($r->opex_sku,0,$length);

            $s = "NF";
            if(strrpos($r->title,"|") > 0)
            {
                $e = explode("|",$r->title);

                $s = $e[1];
            }

            $lengthx = strlen($r->opex_sku) - 1;
            $opex_skux = substr($r->opex_sku,0,$lengthx);
            $makeMaster = $opex_skux;   //work here

           if(isset($ArrPkrPrice[$makeMaster]))
           {
              $cost = $ArrPkrPrice[$makeMaster];
           }

           if(isset($ArrPkrPrice[$makeMaster]))
           {
              $total = (int)$ArrPkrPrice[$makeMaster] * (int)$adQty;
           }







            $arr[] = [$r->c_at,$r->barcode,$type,$seral,$ord,$tagstatus,$opex_sku,$r->title,$s,$adQty,$cost,$total];
        }
             $totalReceived = $total_fba + $total_bulk + $total_merchant;

             $arr[] = ['FBA',$total_fba,'Bulk',$total_bulk,'Merchant',$total_merchant,'Total',$totalReceived];

            // echo "<pre>";
            // print_r($arr);
            // echo "</pre>";
            $fileName = "DropinGatepass_".date('d_m_Y').".xlsx";

            return Excel::download(new JsonExporters($arr,$columns), $fileName);

    //   return Excel::download(new JsonExport($jsonData), 'data.xlsx');
    }

    public function getOrderStatus(){
        $query = DB::table('saleorders')
        ->select('saleorderid','status')
        ->where('order_date','>=',now()->subDays(365))
        ->get();

        $mainarr=[];

        if($query->count() > 0){
            foreach($query as $row){
                $mainarr[$row->saleorderid] =  '<br><span class="badge bg-warning">'.$row->status.'</span>';
            }

            return $mainarr;
        }
    }

   public function DropInDatatable(Request $r)
   {
        $sup_id = $r->sup_id;
        $startDate = $r->start_date;
        $endDate = $r->end_date;

        $ArrPkrPrice = [];

        $getOrdStatus = $this->getOrderStatus();

        $priceQuery = DB::table('productitem')->select(['prodsku','pkr_price'])->where('pkr_price','>',0)->get();

        if($priceQuery->count() > 0)
        {
            foreach($priceQuery as $r)
            {
                $ArrPkrPrice[trim($r->prodsku)]=$r->pkr_price;
            }
        }

        $query = DB::table('new_drop_in_labels')
        ->select(['producttitle','id','saleorderid','prefix','received_log_id','hash','barcode','added_qty','order_number','opex_sku','title','pr_id','pr_item_id','is_sample_freez',DB::raw("COUNT(id) total,DATE_FORMAT(created_at,'%Y-%m-%d') AS c_at")])
        ->leftJoin('productitem as p', 'p.prodsku', '=', 'new_drop_in_labels.opex_sku')
        ->where('sup_id',$sup_id)
        ->where('status','!=',3)
        ->where('is_manual','=',0)
        ->where('prefix','!=','H')
        ->whereBetween(DB::raw("DATE_FORMAT(created_at,'%Y-%m-%d')"), [$startDate, $endDate])
        ->groupBy('dropin_type')
        ->groupBy('opex_sku')
        ->groupBy('order_number')
        ->groupBy('received_log_id')
        ->get();

         return Datatables::of($query)
                     ->addIndexColumn()
                     ->addColumn('orderType', function ($query) {
                         $type = "NF";
                         if($query->prefix == "B")
                         {
                             $type = "BULK";
                         }
                          if($query->prefix == "F")
                         {
                             $type = "FBA";
                         }
                          if($query->prefix == "M")
                         {
                             $type = "Merchant";
                         }
                          if($query->prefix == "W")
                         {
                             $type = "Warehouse";
                         }

                         if($query->prefix == "H")
                         {
                             $type = "Hub Warehouse";
                         }

                         return $type;

                     })
                     ->addColumn('ordNum', function (&$query) use($getOrdStatus) {

                         $ord = $query->order_number;

                         if($query->prefix == "F" || $query->prefix == "B")
                         {
                             $ord = $query->pr_id."-".$query->pr_item_id."-".$query->received_log_id;
                         }

                         $tagstatus = isset($getOrdStatus[$query->saleorderid]) ? $getOrdStatus[$query->saleorderid] : '';

                         return $ord.$tagstatus;

                     })
                     ->addColumn('size', function ($query) {
                        $s = "NF";
                        if(strrpos($query->title,"|") > 0)
                        {
                            $e = explode("|",$query->title);

                            $s = $e[1];
                        }
                        return $s;
                     })
                     ->addColumn('qtyReceived', function ($query) {

                         $qty = 1;

                         if($query->prefix == "F" || $query->prefix == "B")
                         {
                              $qty = $query->total;
                            //$qty = 1;
                         }

                         return '<span class="qty-calc" data-qty="'.$qty.'" data-profix="'.$query->prefix.'">'.$qty.'</span>';

                     })
                     ->addColumn('OpexSku', function ($query) {
                         $length = strlen($query->opex_sku) - 1;
                         $opex_sku = substr($query->opex_sku,0,$length);
                         return $opex_sku;
                     })
                     ->addColumn('serialId', function ($query) {

                         $serial = $query->saleorderid;

                         if($query->prefix == "F" || $query->prefix == "B")
                         {
                              $serial = $query->received_log_id;

                         }

                         return $serial;
                     })
                     ->addColumn('action', function ($query) {
                         $delhtml = '';
                         $userList=[13,114,89];
                         if(in_array(auth()->user()->id,$userList)){
                             if($query->prefix != 'H'){
                               $delhtml = '<a href="javascript:;" data-hash="'.$query->hash.'" data-id="'.$query->id.'" class="btn btn-danger btn-sm btn-remove-from-gatepass"><i class="fa fa-trash"></i></a>';
                             }

                             }


                         if($query->is_sample_freez == 1 && Auth::user()->id!=1)
                         {
                             return '<span class="badge bg-danger">Sample Freez</span>';
                         }else{
                         return '<a href="javascript:;" data-hash="'.$query->hash.'" data-id="'.$query->id.'" data-prefix="'.$query->prefix.'" data-received-id="'.$query->received_log_id.'" class="btn btn-info btn-sm btn-print-from-gatepass"><i class="fa fa-print"></i></a>
                         '.$delhtml.'
                         ';
                         }
                     })
                     ->addColumn('UnitCost', function ($query) use($ArrPkrPrice) {
                         $cost = 0;
                         if(isset($ArrPkrPrice[$query->opex_sku]))
                         {
                            $cost = $ArrPkrPrice[$query->opex_sku];
                         }
                         return $cost;
                     })
                     ->addColumn('TotalCost', function ($query) use($ArrPkrPrice) {
                         $qty = 1;

                         if($query->prefix == "F" || $query->prefix == "B")
                         {
                              $qty = $query->total;

                         }

                         $cost = 0;

                         if(isset($ArrPkrPrice[$query->opex_sku]))
                         {
                            $cost = (int)$ArrPkrPrice[$query->opex_sku] * (int)$qty;
                         }

                         return $cost;
                     })
                    ->rawColumns(['action','qtyReceived','ordNum'])
                    ->make(true);
   }

    public function RemoveDropInItem(Request $req)
   {
       $userid = Auth::user()->id;

       $userinfo = DB::table('st_users')->where('id',$userid)->first();

       $check = DB::table('new_drop_in_labels')
      ->where('id',$req->id)
      ->where('status',1)
      ->get();

      if($check->count() > 0)
      {
          $row = $check->first();

          if($row->dropin_type==1 || $row->dropin_type==2)
          {
               DB::beginTransaction();


               DB::table('generate_new_po')
                  ->where('order_number',trim($row->order_number))
                  ->where('ref_number',trim($row->ref_number))
                  ->update(['dropin_status'=>0,'dropin_date'=>'0000-00-00 00:00:00','dropin_by'=>0]);

               DB::table('saleorders')->whereRaw("order_number='".trim($row->order_number)."' AND reference_no='".trim($row->ref_number)."'")->update([
                    'item_supplier_status'=> 1
            ]);

               if($req->id > 0)
               {
               DB::table('new_drop_in_labels')
                ->where('id',$req->id)
                ->where('status',1)
                ->update(['status'=>3,'cancellation_at'=>date('Y-m-d H:i:s'),'cancellled_by'=>$userid]);
               }

              $loginfo ="Item <strong>Removed</strong> From <strong>Drop-In List</strong> by <strong>".$userinfo->fullname."</strong> From Next Gatepass Page.";

              $loginserted = DB::table('merchantorderlog')->insertGetId([
				      	'logdate' => date('Y-m-d H:i:s'),
						'logtimestamp' => time(),
						'ordernumber' =>trim($row->order_number),
						'orderdbid' =>trim($row->saleorderid),
						'logdetail' => $loginfo,
						'loguser' => $userid
				      ]);

			DB::commit();
			 $title = "Item Removed From Drop-In List ".date('d-m-Y');

			 $body = "<p>Dear Team</p>";

			 $body .= "<p>The Item <strong>Removed</strong> From <strong>Drop-In List</strong> by <strong>".$userinfo->fullname."</strong>.</p>";

			 $body .= "<p>Order Number: <strong>".$row->order_number."</strong></p>";

			 $body .= "<p>Reference Number: <strong>".$row->ref_number."</strong></p>";

			 $body .= "<p>Barcode: <strong>".$row->barcode."</strong></p>";

			 $body .= "<p>Supplier: <strong>".$row->sup_name."</strong></p>";

			 $body .= "<p>Opex SKU: <strong>".$row->opex_sku."</strong></p>";

			 $body .= "<p>Product: <strong>".$row->title."</strong></p>";

			 $body .= "<p>Thanks</p>";

			 $details = [
            'title' => $title,
            'body' => $body

            ];

            $subject = $title;

            $recipients = ['bcc.mailnotifications@gmail.com','abdulrehman.esire@gmail.com','walayatkhan.esire@gmail.com','umarmalik.esire@gmail.com'];

            Mail::to($recipients)->send(new CustMail($details,$subject));



            return response()->json(['code'=>200]);
          }



          if($row->dropin_type==3 || $row->dropin_type==4)
          {
            $pr_id = trim($row->pr_id);
            $item_id = trim($row->pr_item_id);
            $sup_id = trim($row->sup_id);
            $add_qty = $row->added_qty;
            $reclogid = $row->received_log_id;


            $dropin = DB::table('new_drop_in_labels')->where('prefix','=','H')->where('received_log_id',$reclogid)->get();
            if($dropin->count() > 0){
                return response()->json(['code'=>400]);
            }

            $check = DB::table('fba_pro_pr_items')->selectRaw("id,pr_id,supplier_id,opex_sku,given_qty,received_qty,order_type")->whereRaw("id='$item_id' AND pr_id='$pr_id' AND supplier_id='$sup_id' AND status=2")->get();

            if($check->count() > 0)
            {
                 $rw = $check->first();

                 $received_qty = $rw->received_qty;

                 $remain = $received_qty - $add_qty;

                 $typex = $rw->order_type;

                 $fba_item_id = $rw->id;

                 $fba_pr_id = $rw->pr_id;

                 $fba_sup_id = $rw->supplier_id;

                 if ($remain < 0){
                     $remain = 0;
                 }

                DB::table('fba_pro_qty_received_log')->where('id',$reclogid)->delete();

                DB::table('fba_pro_pr_items')->whereRaw("id='$fba_item_id' AND pr_id='$fba_pr_id' AND supplier_id='$fba_sup_id'")->update([

                             'received_qty'=>$remain,
                             'resp'=>json_encode(['last_qty'=>$received_qty,'updated_qty'=>$add_qty,'total_sum'=>$remain]),
                             'last_updated'=>date('Y-m-d H:i:s'),
                             'last_updated_by'=>$userid


                         ]);
                if($reclogid > 0)
                {
                DB::table('new_drop_in_labels')
                ->where('received_log_id',$reclogid)
                ->where('status',1)
                ->update(['status'=>3,'cancellation_at'=>date('Y-m-d H:i:s'),'cancellled_by'=>$userid]);
                }
                $title = "Item Removed From Drop-In List ".date('d-m-Y');

			    $body = "<p>Dear Team</p>";

			    $body .= "<p>The Item <strong>Removed</strong> From <strong>Drop-In List</strong> by <strong>".$userinfo->fullname."</strong>.</p>";

			    $body .= "<p>PR ID: <strong>".$fba_pr_id."</strong></p>";

			    $body .= "<p>PR Item Id: <strong>".$fba_item_id."</strong></p>";

			    $body .= "<p>Barcode: <strong>".$row->barcode."</strong></p>";

			    $body .= "<p>Supplier: <strong>".$row->sup_name."</strong></p>";

			    $body .= "<p>Opex SKU: <strong>".$row->opex_sku."</strong></p>";

			    $body .= "<p>Product: <strong>".$row->title."</strong></p>";

			    $body .= "<p>Quantity: <strong>".$add_qty."</strong></p>";

			    $body .= "<p>Received Log Id Was: <strong>".$reclogid."</strong></p>";

			    $body .= "<p>Thanks</p>";

    			$details = [
                'title' => $title,
                'body' => $body,
                ];

                $subject = $title;

                $recipients = ['bcc.mailnotifications@gmail.com','abdulrehman.esire@gmail.com','walayatkhan.esire@gmail.com','umarmalik.esire@gmail.com'];

                Mail::to($recipients)->send(new CustMail($details,$subject));



                return response()->json(['code'=>200]);


            }else
            {
                return response()->json(['code'=>400]);
            }
          }


      }
      else
      {
          return response()->json(['code'=>400]);
      }

    //   DB::table('new_drop_in_labels')
    //   ->where('id',$req->id)
    //   ->where('status',1)
    //   ->update(['status'=>2,'cancellation_at'=>date('Y-m-d H:i:s'),'cancellled_by'=>$userid]);

    //   return response()->json(['code'=>200]);
   }


   public function PoIssued()
   {
       $data['sup_list'] = DB::table('suppliers')->where('is_active','=',1)
                            ->where('supptype','=',1)->get();

       return view('supplychain.poissued',$data);
   }


      public function slotHubStockForMerchant()
   {
      $r = DB::table('slot_list as s')
    ->select([
        's.next_sku',
        's.pr_id',
        'f.is_nfmo',
        DB::raw('COUNT(s.next_sku) as total')
    ])
    ->leftJoin('fba_pro_purchase_request as f', 'f.id', '=', 's.pr_id')
    ->where('s.slot_status', 1)
    ->where('s.is_reserved', 0)
    ->where('s.is_hub_release', 1)
    ->where('f.is_nfmo','=',0)
    ->groupBy('s.next_sku')
    ->get();

    $arr = [];
    if($r->count() > 0)
    {
        foreach($r as $row)
        {
            $arr[$row->next_sku] = $row->total;
        }
    }

    return $arr;
   }

   public function PoIssuedDataTable(Request $r)
   {

        $sup_id = $r->sup_id;
        $startDate = $r->start_date;
        $endDate = $r->end_date;

       $result = DB::table('generate_new_po as gnp')
    ->leftJoin('saleorders as s', 's.saleorderid', '=', 'gnp.saleorderid')
    ->leftJoin('suppliers as sup', 'sup.supplierid', '=', 'gnp.supplier_id')
    ->select(
        'gnp.id as record_id',
        'gnp.po_date',
        'gnp.supplier_id',
        's.status',
        's.web_link',
        's.order_date',
        's.order_number',
        's.reference_no',
        's.order_sku',
        's.product_title',
        's.big_day_changes',
        DB::raw("CONCAT(sup.firstname, ' ', sup.lastname) as sup_name")
    )
    ->where('s.status', '=', 'In Process')
    ->where('s.is_clearance', '=',0)
    ->whereRaw("s.is_sample_order!= 1 AND s.item_supplier_status IN (1,9)")
    ->where('gnp.is_cancelled',0);

        if($sup_id != 'all'){
           $result->where('gnp.supplier_id', '=', $sup_id);
        }

        $query = $result->get();

        $slotHubStockForMerchant = $this->slotHubStockForMerchant();


         return Datatables::of($query)
                     ->addIndexColumn()
                     ->addColumn('action', function ($query) {
                         return '<div class="form-check"><input class="form-check-input selectcheckbox" id="flexCheckDefault" type="checkbox" value="'.$query->order_number.'" name="selectcheckbox[]" /></div>';
                     })
                     ->addColumn('reference_no', function ($query) use($slotHubStockForMerchant) {

                         $slotTextN = isset($slotHubStockForMerchant[$query->order_sku]) ? '<br /><span class="badge bg-success">Hub Stock ('.$slotHubStockForMerchant[$query->order_sku].')</span>' : '';

                        // $bigDay = '';

                        // if($query->big_day_changes == 3){
                        //     $bigDay = '<br><span class="badge bg-primary">After Christmas</span>';
                        // }
                        // elseif($query->big_day_changes == 2){
                        //     $bigDay = '<br><span class="badge bg-danger">Before Christmas</span>';
                        // }

                        return $query->reference_no.$slotTextN;
                    })
                    ->rawColumns(['action','reference_no'])
                    ->make(true);

   }

   public function supplier_order_cancellation_log($action_id,$supplier_id,$resp_text){
       $user = Auth::user();

        DB::table('supplier_order_cancellation')->insert([
            'action_id' => $action_id,
            'supplier_id' => $supplier_id,
            'resp_text' => json_encode($resp_text),
            'created_by' => $user->id,
            'created_at' => now(),
        ]);
   }

   public function merchantOrderCancellation($supplier_id,$check_box,$resp_text){

       $order = DB::table('generate_new_po')
       ->leftJoin('saleorders','saleorders.saleorderid','=','generate_new_po.saleorderid')
       ->whereIn('generate_new_po.order_number',$check_box)
       ->where('generate_new_po.supplier_id','=',$supplier_id)
       ->get();


       $order_txt = "";

       if(!empty($order)){
          foreach($order as $key => $value){
              $saleorderid = $value->saleorderid;
              $status = $value->status;

              if($status == 'In Process'){

                  if($supplier_id == 54){
                       $updateOne = DB::table('fba_to_merchant_commit')
                        ->where('order_number', $value->order_number)
                        ->update([
                            'status' => 3,
                            'cancelled_at' => now(),
                        ]);

                        $gen_query = DB::table('generate_new_po')
                        ->where('saleorderid', trim($saleorderid))
                        ->where('is_cancelled', 0)
                        ->first();

                        if ($gen_query) {
                            DB::table('generate_new_po')
                                ->where('saleorderid', trim($saleorderid))
                                ->update([
                                    'is_fba_commit' => 3,
                                    'fba_committed_by' => Auth::user()->id,
                                    'fba_committed_at' =>  now(),
                                    'is_cancelled' => 1,
                                    'cancellation_by' => Auth::user()->id,
                                    'cancelleation_at' =>  now(),
                                ]);
                        }
                  }

                if($supplier_id == 1){

                    $up_two = DB::table('qcorder')->where('prnumber', $value->order_number)->update(['remainingqty' => 0]);


                    $gen_query = DB::table('generate_new_po')->where([
                        ['saleorderid', '=', $saleorderid],
                        ['is_cancelled', '=', 0],
                    ])->first();

                    if($gen_query) {
                        DB::table('generate_new_po')
                            ->where('saleorderid', $saleorderid)
                            ->update([
                                'is_cancelled' => 1,
                                'cancellation_by' => Auth::user()->id,
                                'cancelleation_at' => now()
                            ]);
                    }
                }
                 else{
                        DB::table('purchaseorderitemsmerchant')
                    ->where('referenceorderid', $value->order_number)
                    ->update([
                        'orderstat' => 'Cancelled',
                        'is_cancelled' => 1
                    ]);



                $gen_query = DB::table('generate_new_po')
                    ->where([
                        ['saleorderid', '=', $saleorderid],
                        ['is_cancelled', '=', 0],
                    ])
                    ->first();

                if($gen_query) {
                    DB::table('generate_new_po')
                        ->where('saleorderid', $saleorderid)
                        ->where('is_cancelled', '=', 0)
                        ->update([
                            'is_cancelled' => 1,
                            'cancellation_by' => Auth::user()->id,
                            'cancelleation_at' => now()
                        ]);
                }

                 }

                DB::table('saleorders')
                    ->where([
                        ['order_number', '=', $value->order_number],
                        ['status', '=', 'In Process'],
                    ])
                    ->update([
                        'item_supplier_status' => '',
                        'supplier' => '',
                        'supplierid' => 0
                    ]);

                $user = Auth::user()->name;

                $log = [
                    'logdate' => now(),
                    'logtimestamp' => time(),
                    'ordernumber' => $value->order_number,
                    'orderdbid' => $saleorderid,
                    'logdetail' => '<p>Order Status Was <strong>'.$status.'</strong>, Supplier Order Cancelled By <strong>'.$user.'</strong><p>',
                    'loguser' => Auth::user()->id
                ];

                DB::table('merchantorderlog')->insert($log);

                DB::table('slot_list')->where('order_number',$value->order_number)->update([

                        'is_reserved' => 0,
                        'reserved_by' => '',
                        'reserved_at' => '',
                        'file_id' => 0,
                        'is_hub_release' => 1,
                        'order_number' => ''
                ]);


                // if(auth()->user()->id == 114){
                    Helper::supplier_cancellation_whatsapp($supplier_id,$value->order_number,$value->order_sku);
                // }

                $order_txt .= $value->order_number.", ";
              }
              else{
                 $user = Auth::user();

                // assuming $r and $saleorderid are your variables
                DB::table('purchaseorderitemsmerchant')->where('referenceorderid', $value->order_number)
                    ->update([
                        'orderstat' => 'Cancelled',
                        'is_cancelled' => 1,
                    ]);

                $sOrdId = trim($saleorderid);

                $gen_query = DB::table('generate_new_po')->where('saleorderid', $saleorderid)
                    ->where('is_cancelled', 0)
                    ->first();

                if($gen_query) {
                    DB::table('generate_new_po')->where('saleorderid', $saleorderid)
                        ->update([
                            'is_cancelled' => 1,
                            'cancellation_by' => $user->id,
                            'cancelleation_at' => now(),
                        ]);
                }

                DB::table('merchantorderlog')->create([
                    'logdate' => now(),
                    'logtimestamp' => time(),
                    'ordernumber' => $r,
                    'orderdbid' => $saleorderid,
                    'logdetail' => '<p>Order Status Was <strong>'.$status.'</strong>, Supplier Order Cancelled By <strong>'.$user->name.'</strong><p>',
                    'loguser' => $user->id,
                ]);

                // assuming $order_txt was previously declared and it is a string
                $order_txt .= $value->order_number.", ";
              }
              if($resp_text['type'] == 'whcommit'){
                 $this->assignToWh($supplier_id,$check_box,$resp_text);
              }
          }

          $this->supplier_order_cancellation_log(1,$supplier_id,$resp_text);
          return response()->json([
              'status' => true,
              'type' => 'cancel',
              'msg' => 'Order cancelled successfully!'
            ]);
       }
   }

   public function merchantOrderCancellationV2($supplier_id,$check_box,$resp_text){

       $order = DB::table('generate_new_po')
       ->leftJoin('saleorders','saleorders.saleorderid','=','generate_new_po.saleorderid')
       ->whereIn('generate_new_po.order_number',$check_box)
       ->get();


       $order_txt = "";

       if(!empty($order)){
          foreach($order as $key => $value){
              $saleorderid = $value->saleorderid;
              $status = $value->status;

              if($status == 'In Process'){

                  if($supplier_id == 54){
                       $updateOne = DB::table('fba_to_merchant_commit')
                        ->where('order_number', $value->order_number)
                        ->update([
                            'status' => 3,
                            'cancelled_at' => now(),
                        ]);

                        $gen_query = DB::table('generate_new_po')
                        ->where('saleorderid', trim($saleorderid))
                        ->where('is_cancelled', 0)
                        ->first();

                        if ($gen_query) {
                            DB::table('generate_new_po')
                                ->where('saleorderid', trim($saleorderid))
                                ->update([
                                    'is_fba_commit' => 3,
                                    'fba_committed_by' => Auth::user()->id,
                                    'fba_committed_at' =>  now(),
                                    'is_cancelled' => 1,
                                    'cancellation_by' => Auth::user()->id,
                                    'cancelleation_at' =>  now(),
                                ]);
                        }
                  }

                if($supplier_id == 1){

                    $up_two = DB::table('qcorder')->where('prnumber', $value->order_number)->update(['remainingqty' => 0]);


                    $gen_query = DB::table('generate_new_po')->where([
                        ['saleorderid', '=', $saleorderid],
                        ['is_cancelled', '=', 0],
                    ])->first();

                    if($gen_query) {
                        DB::table('generate_new_po')
                            ->where('saleorderid', $saleorderid)
                            ->update([
                                'is_cancelled' => 1,
                                'cancellation_by' => Auth::user()->id,
                                'cancelleation_at' => now()
                            ]);
                    }
                }
                 else{
                        DB::table('purchaseorderitemsmerchant')
                    ->where('referenceorderid', $value->order_number)
                    ->update([
                        'orderstat' => 'Cancelled',
                        'is_cancelled' => 1
                    ]);



                $gen_query = DB::table('generate_new_po')
                    ->where([
                        ['saleorderid', '=', $saleorderid],
                        ['is_cancelled', '=', 0],
                    ])
                    ->first();

                if($gen_query) {
                    DB::table('generate_new_po')
                        ->where('saleorderid', $saleorderid)
                        ->where('is_cancelled', '=', 0)
                        ->update([
                            'is_cancelled' => 1,
                            'cancellation_by' => Auth::user()->id,
                            'cancelleation_at' => now()
                        ]);
                }

                 }

                DB::table('saleorders')
                    ->where([
                        ['order_number', '=', $value->order_number],
                        ['status', '=', 'In Process'],
                    ])
                    ->update([
                        'item_supplier_status' => '',
                        'supplier' => '',
                        'supplierid' => 0
                    ]);

                $user = Auth::user()->name;

                $log = [
                    'logdate' => now(),
                    'logtimestamp' => time(),
                    'ordernumber' => $value->order_number,
                    'orderdbid' => $saleorderid,
                    'logdetail' => '<p>Order Status Was <strong>'.$status.'</strong>, Supplier Order Cancelled By <strong>'.$user.'</strong><p>',
                    'loguser' => Auth::user()->id
                ];

                DB::table('merchantorderlog')->insert($log);

                DB::table('slot_list')->where('order_number',$value->order_number)->update([

                        'is_reserved' => 0,
                        'reserved_by' => '',
                        'reserved_at' => '',
                        'file_id' => 0,
                        'is_hub_release' => 1,
                        'order_number' => ''
                ]);

                $order_txt .= $value->order_number.", ";
              }
              else{
                 $user = Auth::user();

                // assuming $r and $saleorderid are your variables
                DB::table('purchaseorderitemsmerchant')->where('referenceorderid', $value->order_number)
                    ->update([
                        'orderstat' => 'Cancelled',
                        'is_cancelled' => 1,
                    ]);

                $sOrdId = trim($saleorderid);

                $gen_query = DB::table('generate_new_po')->where('saleorderid', $saleorderid)
                    ->where('is_cancelled', 0)
                    ->first();

                if($gen_query) {
                    DB::table('generate_new_po')->where('saleorderid', $saleorderid)
                        ->update([
                            'is_cancelled' => 1,
                            'cancellation_by' => $user->id,
                            'cancelleation_at' => now(),
                        ]);
                }

                DB::table('merchantorderlog')->create([
                    'logdate' => now(),
                    'logtimestamp' => time(),
                    'ordernumber' => $r,
                    'orderdbid' => $saleorderid,
                    'logdetail' => '<p>Order Status Was <strong>'.$status.'</strong>, Supplier Order Cancelled By <strong>'.$user->name.'</strong><p>',
                    'loguser' => $user->id,
                ]);

                // assuming $order_txt was previously declared and it is a string
                $order_txt .= $value->order_number.", ";
              }
              if($resp_text['type'] == 'whcommit'){
                 $this->assignToWh($supplier_id,$check_box,$resp_text);
              }
          }

          $this->supplier_order_cancellation_log(1,$supplier_id,$resp_text);
          return response()->json([
              'status' => true,
              'type' => 'cancel',
              'msg' => 'Order cancelled successfully!'
            ]);
       }
   }

   public function assignToWh($supplier_id,$check_box,$resp_text){
       $html ='';
       //$saleorderid = $check_box;

        $saleOrder = DB::table('saleorders')->where('order_number', $check_box)
            ->where('status', 'In Process')
            ->where('item_supplier_status', 0)
            ->first();

        if($saleOrder)
        {
            $productItem = DB::table('productitem')->where('prodsku', $saleOrder->order_sku)->first();

            if($productItem)
            {
                $commit_qty = 1;
                $stock = 1;

                if($stock >= $commit_qty)
                {
                    $qcOrder = DB::table('qcorder')->insert([
                        'commitsku' => $saleOrder->order_sku,
                        'commitqty' => $commit_qty,
                        'ordertype' => 'merchant',
                        'prnumber' => $saleOrder->order_number,
                        'commitedon' => now(),
                        'remainingqty' => $commit_qty
                    ]);

                    DB::table('saleorders')->where('order_number', $check_box)
                    ->where('status', 'In Process')
                    ->where('item_supplier_status', 0)->update([
                        'item_supplier_status' => 1,
                        'supplier' => 'Ware House',
                        'supplierid' => 1
                    ]);

                    $upgen = DB::table('generate_new_po')->where('saleorderid', $saleOrder->saleorderid)
                        ->where('order_number', $saleOrder->order_number)
                        ->update([
                            'is_cancelled' => 1,
                            'cancellation_by' => Auth::user()->name,
                            'cancelleation_at' => now()
                        ]);

                    $insert_po_gen = DB::table('generate_new_po')->insert([
                        'saleorderid' => $saleOrder->saleorderid,
                        'order_number' => $saleOrder->order_number,
                        'ref_number' => $saleOrder->reference_no,
                        'order_date' => $saleOrder->order_date,
                        'opex_sku' => $saleOrder->order_sku,
                        'supplier_id' => 1,
                        'po_date' => now(),
                        'po_by' => Auth::user()->name,
                        'is_warehouse_commit' => 1,
                        'warehouse_commited_by' => Auth::user()->name,
                        'warehouse_commited_at' => now()
                    ]);

                    // Get user
                    $user = Auth::user()->name;

                    // Log
                    $log =DB::table('merchantorderlog')->insert([
                        'logdate' => now(),
                        'logtimestamp' => time(),
                        'ordernumber' => $saleOrder->order_number,
                        'orderdbid' => $saleOrder->saleorderid,
                        'logdetail' => "<p>Order Commited from Inhouse Warehouse by <em><strong>{$user}</strong></em> via <em>process supplier</em>.</p>",
                        'loguser' => Auth::user()->id,
                    ]);

                    DB::table('slot_list')->where('order_number',$saleOrder->order_number)->update([

                        'is_reserved' => 0,
                        'reserved_by' => '',
                        'reserved_at' => '',
                        'file_id' => 0,
                        'is_hub_release' => 1,
                        'order_number' => ''
                ]);

                    // Warehouse marking
                    $remian_qty = ($stock - $commit_qty);
                    $marked = DB::table('warehosue_marked')->insert([
                        'opex_sku' => $saleOrder->order_sku,
                        'last_qty' => $stock,
                        'marked_qty' => $commit_qty,
                        'remaining_qty' => $remian_qty,
                        'marked_type' => 1,
                        'created_by' => Auth::user()->id,
                        'created_at' => now()
                    ]);

                    $html.='Warehouse Committed Successfully!';
                    return response()->json([
                        'status' => true,
                        'type' => 'cancel',
                        'msg' => $html
                    ]);
                }
                else{
                 $html.='Sorry Quantity Not Found In Warehouse Stock.';
                 return response()->json([
                        'status' => false,
                        'type' => 'cancel',
                        'msg' => $html
                    ]);
                }

            }
            else{
              $html.='Please Check Order Status, Supplier Already Assigned.';
               return response()->json([
                        'status' => false,
                        'type' => 'cancel',
                        'msg' => $html
                    ]);
            }


        }
        else{
          $html.='Please Check Order Status, Supplier Already Assigned.';
           return response()->json([
                        'status' => false,
                        'type' => 'cancel',
                        'msg' => $html
                    ]);
        }

   }

    public function getSupplierName($switch_supplier_id)
    {
        $supplier = DB::table('suppliers')->where('supplierid', $switch_supplier_id)->first();

        return $supplier ? $supplier->firstname . ' ' . $supplier->lastname : '';
    }

    public function getNextIncrement($table)
    {
        $result = DB::select(DB::raw("SHOW TABLE STATUS LIKE '$table'"));
        return $result[0]->Auto_increment ?? null;
    }


     public function NBatchId()
   {
     $bSql = DB::table('generate_new_po')
     ->select('batch_number')
     ->where('batch_number', '!=', '')
     ->where('batch_number', '>', 0)
     ->orderBy('batch_number', 'desc')
     ->limit(1)
     ->get();

     if($bSql->count() > 0)
     {
         $batch = $bSql->first()->batch_number + 1;
     }
     else
     {
         $batch = 1001;
     }

     return $batch;
   }

   public function AssignBatchN($arr)
   {
       $ck = DB::table('generate_new_po as g')
    ->select('g.id', 'g.supplier_id')
    ->where('g.dropin_status',0)
    ->where('g.is_cancelled',0)
    ->whereIn('g.id', $arr)
    ->where(function($query) {
        $query->whereNull('g.batch_number')
              ->orWhere('g.batch_number', '')
              ->orWhere('g.batch_number', 0);
    })
    ->orderBy('g.supplier_id', 'asc')
    ->get();

    // $suppliers = DB::table('suppliers')
    // ->select('supplierid', DB::raw("CONCAT(firstname, ' ', lastname) AS sup_name"))
    // ->get();

    // $supplierArray = [];

    // foreach ($suppliers as $supplier) {
    //     $supplierArray[$supplier->supplierid] = $supplier->sup_name;
    // }

    if($ck->count() > 0)
    {
        $batchArr = [];

        foreach($ck as $r)
        {
            // if($r->supplier_id == 40 || $r->supplier_id==76)
            // {

            if(isset($batchArr[$r->supplier_id]))
            {
                $batchNumber = $batchArr[$r->supplier_id];
            }
            else
            {
                $batchArr[$r->supplier_id] = $this->NBatchId();

                $batchNumber = $batchArr[$r->supplier_id];
            }

            // $cSql = DB::table('generate_new_po')
            //         ->where('batch_number', $batchNumber)
            //         ->count();

            // if($cSql > 10)
            // {
            //     $rowSql = DB::table('generate_new_po')
            //                 ->select('batch_number')
            //                 ->where('batch_number', '!=', '')
            //                 ->where('batch_number', '>', 0)
            //                 ->orderBy('batch_number', 'desc')
            //                 ->limit(1)
            //                 ->first();




            // }

             DB::table('generate_new_po')
                ->where('id',$r->id)
                ->where('supplier_id',$r->supplier_id)
                ->where('is_cancelled',0)
                ->where(function($query) {
                    $query->where('batch_number', '')
                    ->orWhereNull('batch_number');
                })
                ->update([
                    'batch_number'=>$batchNumber,
                    'batch_updated'=>now()
                    ]);
            //}

        }

        /*foreach($batchArr as $supid => $b)
        {
            // if($supid == 40 || $supid==76)
            // {

                Helper::new_merchant_batch_whatsapp_msg($supid,$b);

            //}
        }*/
    }

   }




   public function switchSupplier($supplier_id,$check_box,$switch_supplier_id){
        $error = '';
        $success = "";
        $today = date('Y-m-d H:i:s');

        $order = DB::table('generate_new_po')
       ->leftJoin('saleorders','saleorders.saleorderid','=','generate_new_po.saleorderid')
       ->whereIn('generate_new_po.order_number',$check_box)
       ->where('generate_new_po.supplier_id','=',$supplier_id)
       ->get();


        $assignIds = [];

        foreach ($order as $key => $orders) {
            $order_number = $orders->order_number;
            $reference_no = $orders->reference_no;
            $order_sku = $orders->order_sku;
            $order_date = $orders->order_date;
            $saleorderid = $orders->saleorderid;

            DB::table('generate_new_po')->where('saleorderid', $saleorderid)->where('order_number', $order_number)->update([
                'is_cancelled' => 1,
                'cancellation_by' => Auth::user()->id,
                'cancelleation_at' => $today,
            ]);

            $genId = DB::table('generate_new_po')->insertGetId([
                'saleorderid' => $saleorderid,
                'order_number' => $order_number,
                'ref_number' => $reference_no,
                'order_date' => $order_date,
                'opex_sku' => $order_sku,
                'supplier_id' => $switch_supplier_id,
                'po_date' => $today,
                'po_by' => Auth::user()->id,
            ]);


            $assignIds[]=$genId;

            $qu = DB::table('saleorders')->where('saleorderid', $saleorderid)->first();

            if ($switch_supplier_id != 14 && $switch_supplier_id != 15 && $switch_supplier_id != 19) {

                DB::table('saleorders')->where('saleorderid', $saleorderid)->update([
                    'item_supplier_status' => 1,
                    'supplier' => $this->getSupplierName($switch_supplier_id),
                    'supplierid' => $switch_supplier_id,
                ]);

                $pid = $this->getNextIncrement('purchaseorder');

                $po = sprintf('%06d', (0000 + $pid));

                $porder = 'PO-' . $po;

                $purchaseOrder = DB::table('purchaseorder')->insertGetId([
                    "purchaseorderno" => $porder,
                    "supplier" => $switch_supplier_id,
                    "purchasedate" => $today,
                    "deliverdate" => "0000-00-00 00:00:00",
                    "ptype" => "merchant",
                ]);


                $purchaseOrderItem =  DB::table('purchaseorderitems')->insertGetId([
                    "itemsku" => $order_sku,
                    "itemqty" => 1,
                    "itemprice" => 0,
                    "orderid" => $purchaseOrder,
                    "orderstat" => "Pending",
                    "ordsupplier" => $switch_supplier_id,
                    "ordtype" => "merchant",
                    "orddate" => $today,
                ]);

                DB::table('purchaseorderitemsmerchant')->insertGetId([
                    'orderitemid' => $purchaseOrderItem,
                    'orderid' => $purchaseOrder,
                    'referenceorderid' => $order_number,
                    'producttitle' => $qu->product_title,
                    'orderstat' => 'Pending',
                    'ordsupplier' => $switch_supplier_id,
                    "sku" => $order_sku,
                ]);

                $user = Auth::user()->name;

                DB::table('merchantorderlog')->insert([
                    'logdate' => $today,
                    'logtimestamp' => time(),
                    'ordernumber' => $order_number,
                    'orderdbid' => $saleorderid,
                    'logdetail' => '<p>Order <strong>#' . $order_number . '</strong> Status Was <strong>' . $qu->status . '</strong> and The Order Assigned to <strong>' . $this->getSupplierName($switch_supplier_id) . ' </strong> By <strong>' . $user . '</strong> </p>',
                    'loguser' => Auth::user()->id,
                ]);


                DB::table('slot_list')->where('order_number',$order_number)->update([

                        'is_reserved' => 0,
                        'reserved_by' => '',
                        'reserved_at' => '',
                        'file_id' => 0,
                        'is_hub_release' => 1,
                        'order_number' => ''
                ]);
            }

          if ($switch_supplier_id == 14) {
            $supplier = "SBU UNIT";
            }
            elseif ($switch_supplier_id == 19) {
                $supplier = "MCF Amazon";
            }
            elseif ($switch_supplier_id == 15) {
                $supplier = "TITAN SUPPLIER";
            }

        if (isset($supplier)) {
            DB::table('saleorders')->where('saleorderid', $saleorderid)->update([
                'item_supplier_status' => 4,
                'supplier' => $supplier,
                'supplierid' => $switch_supplier_id,
                'status' => 'Awaiting Fulfillment',
            ]);

            DB::table('usashipped')->insert([
                'saleorderid' => $saleorderid,
                'referenceorderid' => $order_number,
                'producttitle' => $qu->product_title,
                'supplier' => $supplier,
                'orderstat' => 'Pending',
                'assigndate' => date('Y-m-d'),
                'targetsource' => 'freshorder',
            ]);

            $user = Auth::user()->name;

            DB::table('merchantorderlog')->insert([
                'logdate' => $today,
                'logtimestamp' => time(),
                'ordernumber' => $order_number,
                'orderdbid' => $saleorderid,
                'logdetail' => '<p>Order has been reserved by <em><strong>' . $user . '</strong></em> against <u>' . $supplier . '</u></p>',
                'loguser' => Auth::user()->id,
            ]);
        }

        $success .= $order_number . ", ";
        }

        if(count($assignIds) > 0)
        {
            $this->AssignBatchN($assignIds);
        }

        return response()->json([
            'status' => true,
            'type' => 'switch',
            'msg' => 'Supplier changed successfully!'
        ]);
   }


   public function CancelOrderReInProcess(Request $request)
   {
       $supplier_id = $request->supplier_id;
       $check_box = explode(',', $request->selectedCheckBox);
       $switch_supplier_id = $request->switch_supplier_id;
       $resp_text = $request->all();

       if($request->type == 'reinprocess'){
           if($supplier_id == 'all'){
               return $this->merchantOrderCancellationv2($supplier_id,$check_box,$resp_text);
           }
           else{
               return $this->merchantOrderCancellation($supplier_id,$check_box,$resp_text);
           }

       }
       elseif($request->type == 'whcommit'){
           return $this->merchantOrderCancellation($supplier_id,$check_box,$resp_text);
       }
       else{
           return $this->switchSupplier($supplier_id,$check_box,$switch_supplier_id);
       }
   }


   public function get_suppliers_with_cost() {
        // First query
        $queryx = DB::table('supplier_items_cost')
                    ->select('opex_sku_master', 'opex_sku_child', 'cost', 'cmt_cost', 'supplier_id')
                    ->get();

        $costArr = [];

        foreach($queryx as $r) {
            $costArr[$r->opex_sku_child . "_" . $r->supplier_id] = "Cost: " . $r->cost . ", CMT Cost: " . $r->cmt_cost;
        }

        // Second query
        $query = DB::table('productitem as p')
                    ->leftJoin('suppliers as s_ps', 's_ps.supplierid', '=', 'p.primary_supplier')
                    ->leftJoin('suppliers as s_ss', 's_ss.supplierid', '=', 'p.secondary_supplier')
                    ->leftJoin('suppliers as s_ts', 's_ts.supplierid', '=', 'p.tertiary_supplier')
                    ->select('prodsku', 'primary_supplier', 'secondary_supplier', 'tertiary_supplier', DB::raw("CONCAT(s_ps.firstname,' ',s_ps.lastname) as ps_name"), DB::raw("CONCAT(s_ss.firstname,' ',s_ss.lastname) as ss_name"), DB::raw("CONCAT(s_ts.firstname,' ',s_ts.lastname) as ts_name"))
                    ->where('p.is_discarded', 0)
                    ->get();

        $arr = [];

        foreach($query as $r) {
            $supArr = [];

            if(!empty($r->ps_name)) {
                $supArr['primary_supplier_id'] = $r->primary_supplier;
                $supArr['primary_supplier'] = ucfirst($r->ps_name) . ",  ";
            }

            if(!empty($r->ss_name)) {
                $supArr['secondary_supplier_id'] = $r->secondary_supplier;
                $supArr['secondary_supplier'] = ucfirst($r->ss_name) . ",  ";
            }

            if(!empty($r->ts_name)) {
                $supArr['tertiary_supplier_id'] = $r->tertiary_supplier;
                $supArr['tertiary_supplier'] = ucfirst($r->ts_name) . ",  ";
            }

            $arr[$r->prodsku] = $supArr;
        }

        return $arr;
    }

   public function DropInCount(){
       $arr = [];

       $sql = DB::table('new_drop_in_labels')
    ->select('sup_id', 'prefix', DB::raw('COUNT(id) as total'))
    ->where('status', 1)
    ->whereBetween(DB::raw("DATE_FORMAT(created_at, '%Y-%m-%d')"), [
        DB::raw("DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 7 DAY), '%Y-%m-%d')"),
        DB::raw("DATE_FORMAT(NOW(), '%Y-%m-%d')")
    ])
    ->groupBy('sup_id')
    ->get();

     if($sql->count() > 0)
     {
             foreach($sql as $r)
             {
                 $arr[$r->sup_id]=$r->total;
             }

     }

     return $arr;

   }

   public function PendingOrdersSup(){

    $Merchant = [];

    $FBAandBulk = [];

    $MerchantOrders = DB::table('saleorders as s')
    ->leftJoin('generate_new_po as gp', 'gp.saleorderid', '=', 's.saleorderid')
    ->select('gp.supplier_id', DB::raw('COUNT(gp.id) as total'))
    ->where('s.status', 'In Process')
    ->where('s.item_supplier_status', 1)
    ->where('gp.dropin_status', 0)
    ->where('gp.is_cancelled', 0)
    ->groupBy('gp.supplier_id')
    ->get();

    if($MerchantOrders->count() > 0)
    {
        foreach($MerchantOrders as $item)
        {
            $Merchant[$item->supplier_id]=$item->total;
        }
    }


    $FbaOrders = DB::table('fba_pro_pr_items as fi')
    ->leftJoin('fba_pro_purchase_request as fppr', 'fppr.id', '=', 'fi.pr_id')
    ->selectRaw("
        CASE WHEN fppr.order_type = 1 THEN 'FBA' ELSE 'BULK' END AS ord_type,
        fi.supplier_id,
        (SUM(fi.given_qty) - SUM(fi.received_qty)) as total
    ")
    ->where('fi.status', 2)
    ->whereRaw('fi.received_qty < fi.given_qty')
    ->groupBy('fi.supplier_id')
    ->get();

    if($FbaOrders->count() > 0)
    {
        foreach($FbaOrders as $item)
        {
            $FBAandBulk[$item->supplier_id]=$item->total;
        }
    }

    return ['merchant'=>$Merchant,'fba'=>$FBAandBulk];

   }

   public function all_suppliers($arr,$key){

	    $suppliers_options = "<select name='supplier_id[".$key."]' data-id='".$key."' class='selected-supplier form-select' style='width:100%;'>";

		$suppliers_options .= "<option value='' selected>Select Supplier</option>";
		if(count($arr) > 0){
		foreach($arr as $sup)
		{
			$suppliers_options .= "<option value='".$sup->supplierid."'>".$sup->sup_name."</option>";
		}
		}
		$suppliers_options .= "</select>";

		return $suppliers_options;
   }


   public function switchSupplierNew($order_number,$supplier_id){

        $query = DB::table('generate_new_po')
           ->leftJoin('saleorders','saleorders.saleorderid','=','generate_new_po.saleorderid')
           ->select('generate_new_po.saleorderid','saleorders.team','generate_new_po.opex_sku')
           ->where('generate_new_po.order_number',$order_number)
           ->where('generate_new_po.supplier_id','=',$supplier_id)
           ->where('generate_new_po.is_cancelled','=',0)
           ->get();

           $dropInArr = $this->DropInCount();

           $pendingArr = $this->PendingOrdersSup();

           $arrSupInfoAll = DB::table('suppliers')
                            ->select(DB::raw("supplierid, CONCAT(firstname,' ',lastname) as sup_name"))
                            ->get();


          if($query->count() > 0){

              $d =  $query->first();

              $team_selection = $this->all_suppliers($arrSupInfoAll,$d->saleorderid);

               if($d->team=="eSire (Jackets, Coat and Vest)")
    		    {
    		        $arr = $this->get_suppliers_with_cost();
    		        $team_selection = app(\App\Http\Controllers\ProcesssupplierController::class)->esire_team_supplier($arr,$d->opex_sku,$d->saleorderid,$pendingArr,$dropInArr);
    		        //dd($team_selection);
    		    }

    		    if($d->team=="SBU (Small Business Unit)")
    		    {
    		        $team_selection = app(\App\Http\Controllers\ProcesssupplierController::class)->other_team_supplier($d->saleorderid,"SBU (Small Business Unit)",14);
    		    }

    		    if($d->team=="Sublime Team (Suits , Tuxedo)")
    		    {
    		        $team_selection = app(\App\Http\Controllers\ProcesssupplierController::class)->other_team_supplier($d->saleorderid,"Sublime Team (Suits , Tuxedo)",13);
    		    }

                if($d->team=="Miracle (Mugs, Hoodie and Rugs)")
    		    {
    		        $team_selection = app(\App\Http\Controllers\ProcesssupplierController::class)->other_team_supplier($d->saleorderid,"Titan Team",15);
    		    }

    		    if(empty($team_selection))
    		    {
    		        $team_selection = $this->all_suppliers($arrSupInfoAll,$d->saleorderid);
    		    }

    		    return response()->json([
    		        'stauts' => true,
    		        'html' => $team_selection,
    		        'data' => $d->saleorderid
    		    ]);
          }
   }


   public function paypal_supported_shippers(){
    $enumared_value = array(
        'ARAMEX','B_TWO_C_EUROPE','CJ_LOGISTICS','CORREOS_EXPRESS','DHL_ACTIVE_TRACING','DHL_BENELUX','DHL_GLOBAL_MAIL','DHL_GLOBAL_MAIL_ASIA','DHL','DHL_GLOBAL_ECOMMERCE','DHL_PACKET','DPD','DPD_LOCAL','DPD_LOCAL_REF','DPE_EXPRESS','DPEX','DTDC_EXPRESS','ESHOPWORLD','FEDEX','FLYT_EXPRESS','GLS','IMX','INT_SUER','LANDMARK_GLOBAL','MATKAHUOLTO','OMNIPARCEL','ONE_WORLD','POSTI','RABEN_GROUP','SF_EXPRESS','SKYNET_Worldwide','SPREADEL','TNT','UPS','UPS_MI','WEBINTERPRET','CORREOS_AG','EMIRATES_POST','OCA_AR','ADSONE','AUSTRALIA_POST','TOLL_AU','BONDS_COURIERS','COURIERS_PLEASE','DHL_AU','DTDC_AU','FASTWAY_AU','HUNTER_EXPRESS','SENDLE','STARTRACK','STARTRACK_EXPRESS','TNT_AU','TOLL','UBI_LOGISTICS','AUSTRIAN_POST_EXPRESS','AUSTRIAN_POST','DHL_AT','BPOST','BPOST_INT','MONDIAL_BE','TAXIPOST','QUANTIUM','ABC_PACKAGE','AIRBORNE_EXPRESS','ASENDIA_US','CPACKET','ENSENDA','ESTES','FASTWAY_US','GLOBEGISTICS','INTERNATIONAL_BRIDGE','ONTRAC','RL_US','RRDONNELLEY','USPS'
    );
    return $enumared_value;
    }

    public function get_new_access_token_again()
    {

    $curl = curl_init();
    curl_setopt_array($curl, array(
      CURLOPT_URL => 'https://api.paypal.com/v1/oauth2/token',
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING => '',
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_TIMEOUT => 0,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => 'POST',
      CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
      CURLOPT_HTTPHEADER => array(
        'Content-Type: application/x-www-form-urlencoded',
        'Authorization: Basic QWRmeDlWUEFVSlc0SVhQM3ZHLWFWNVc5cnktM1VNUklqRE1DNzFvckdMYnVmdmplQ0RfdWFQTHpxR2Q5YVp0NFBRbWMyVEVPOUJ4SGM4eGY6RUN2UXZaejdRakpXUnR6MXExNUstSFVBOGgyRmxycG1GdHA5MmxJUm5LaUNJWnp2cHF3Q1poUE1JeTVWMGFEeWtiTzd1M0VOTWUzbFUtS1A='
      ),
    ));

    $response = curl_exec($curl);
    curl_close($curl);

    $descArr = json_decode($response,true);
    $newtoken =trim($descArr['access_token']);
    return $newtoken;

    }

    public function update_paypal_tracking($data,$options){
    $new_token_x = $this->get_new_access_token_again();
    $response = '';
    if($options->testmode == 'disabled'){
        $token = $options->ptn;
        $url = "https://api.paypal.com/v1/shipping/trackers-batch";
        $headers = [
            "Content-Type: application/json",
            "Authorization: Bearer $new_token_x"
        ];
        $fields = array('sent_track' => 1);
        $fields['live_sent'] = 1;
        $fields['senton'] = date('Y-m-d H:i:s', time());

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        $result = curl_exec($ch);
        $json = json_decode($result, true);

        $op = print_r($result, true) . "\n" . print_r($data, true);
        $file = 'paypal_responses/paypal_'.time().'.txt';
        Storage::disk('local')->put($file, $result);

        if(!preg_match('/(INVALID_REQUEST|RESOURCE_NOT_FOUND|invalid_token)/', $result)){
            $response = $json['tracker_identifiers'];
            foreach($response as $txnid){
                $txn_id = $txnid['transaction_id'];
               DB::table('paypal_responses')
                ->where('txn_id', $txn_id)
                ->update($fields);
            }

        }

     }
        return $response;
    }

    public function post_data($order){
	    Storage::disk('local')->put('post1.txt', $order);

        $get_order = DB::table('saleorders')
            ->select('saleorderid', 'web_link', 'reference_no', 'shipping_date', 'status')
            ->where('saleorderid', $order)
            ->orderBy('saleorderid', 'desc')
            ->first();

        $data = [
            'status' => $get_order->status,
            'ordernum' => $get_order->reference_no,
            'shipdate' => $get_order->shipping_date,
        ];

        $url = '';
        switch($get_order->web_link) {
            case 'Fan Jackets':
                $url = 'https://www.fanjackets.com/app/email_crm/upload/update_from_opex.php';
                break;
            case 'F Jackets':
                $url = 'https://www.fjackets.com/app/email_crm/upload/update_from_opex.php';
                break;
            case 'Angel':
                $url = 'https://www.angeljackets.com/apps/email_crm/upload/update_from_opex.php';
                break;
        }

        // Original PHP code: file_put_contents('post2.txt', print_r($data, true));
        Storage::disk('local')->put('post2.txt', print_r($data, true));

        if($url != '') {
            Http::post($url, $data);
            Storage::disk('local')->put('post3.txt', 'post_data_url executed' . $url);
        }
	}

    public function getSampleOrder(){
        $query = DB::table('saleorders')
        ->select('order_number')
        ->where('is_sample_order',1)
        ->where('order_date', '>', now()->subDays(120))
        ->get();

        $mainarr=[];

        if($query->count() > 0){
            foreach($query as $row){
                $mainarr[$row->order_number] = $row->order_number;
            }

            return $mainarr;
        }
    }


    public function printBtn($sup_id,$start_date,$end_date){

        $user = Auth::user()->name;

        $supplier = DB::table('suppliers')->where('supplierid','=',$sup_id)->first();
        $supplier_name = $supplier->firstname.' '.$supplier->lastname;

        $today = date('Y-m-d H:i:s');

        $total = 0;
        $merchant_total = 0;
        $bulk_total = 0;
        $fba_total = 0;
        $cost=0;
        $utotal=0;

        $getSample = $this->getSampleOrder();

        $html='<table id="CompiledGatePass" class="table table-striped table-bordered dt-responsive nowrap" style="width:100%;border-collapse:collapse;border:1px solid black;text-align:center;    font-size: 14px;
        font-family: Circular-Loom;">
                                                <thead>
                                                <tr><th colspan="9" style=""><h2 style="margin:1px;">Supplier Receiving Gate Pass</h2></th></tr>

                                                <tr>
                                                <th colspan="6" style="text-align:left;padding:5px;">'.$supplier_name.'</th>
                                                <th colspan="3" style="text-align:right;padding:5px;">'.$start_date.' To '.$end_date.'</th>
                                                </tr>


                                                    <tr>

                                                        <th style="border:1px solid black;">Type</th>
                                                        <th style="border:1px solid black;">Date</th>
                                                        <th style="border:1px solid black;">Serial No.</th>
                                                        <th style="border:1px solid black;">Order No.</th>
                                                        <th style="border:1px solid black;">SKU</th>
                                                        <th style="border:1px solid black;">Product</th>
                                                        <th style="border:1px solid black;">Size</th>
                                                        <th style="border:1px solid black;">Qty</th>
                                                        <th style="border:1px solid black; display:none;">Unit Cost</th>
                                                        <th style="border:1px solid black; display:none;">Total</th>
                                                    </tr>
                                                </thead><tbody>';

        $ArrPkrPrice = [];

        $priceQuery = DB::table('productitem')->select(['prodsku','pkr_price'])->where('pkr_price','>',0)->get();

        if($priceQuery->count() > 0)
        {
            foreach($priceQuery as $r)
            {
                $ArrPkrPrice[trim($r->prodsku)]=$r->pkr_price;
            }
        }

        $query_one = DB::table('new_drop_in_labels')
        ->select(['id','dropin_type','saleorderid','prefix','received_log_id','hash','barcode','added_qty','order_number','opex_sku','title','pr_id','pr_item_id',DB::raw("COUNT(id) total,DATE_FORMAT(created_at,'%Y-%m-%d') AS c_at")])
        ->where('sup_id',$sup_id)
        ->where('status','!=',3)
        ->where('is_manual','=',0)
        ->where('prefix','!=','H')
        ->whereBetween(DB::raw("DATE_FORMAT(created_at,'%Y-%m-%d')"), [$start_date, $end_date])
        ->groupBy('dropin_type')
        ->groupBy('opex_sku')
        ->groupBy('order_number')
        ->groupBy('received_log_id')
        ->get();


        if (count($query_one) > 0) {
            foreach($query_one as $query) {

                        $type = "NF";
                         if($query->prefix == "B")
                         {
                             $type = "BULK";
                         }
                          if($query->prefix == "F")
                         {
                             $type = "FBA";
                         }
                          if($query->prefix == "M")
                         {
                             $type = "Merchant";
                         }
                          if($query->prefix == "W")
                         {
                             $type = "Warehouse";
                         }

                          $serial = $query->saleorderid;
                         if($query->prefix == "F" || $query->prefix == "B")
                         {
                              $serial = $query->received_log_id;
                         }

                         $ord = $query->order_number;
                         if(isset($getSample[$query->order_number]))
                         {
                             $ord = $query->order_number.'-s';
                         }

                         if($query->prefix == "F" || $query->prefix == "B")
                         {
                             $ord = $query->pr_id."-".$query->pr_item_id."-".$query->received_log_id;
                         }

                        $length = strlen($query->opex_sku) - 1;
                        $opex_sku = substr($query->opex_sku,0,$length);

                        $s = "NF";
                        if(strrpos($query->title,"|") > 0)
                        {
                            $e = explode("|",$query->title);

                            $s = $e[1];
                        }

                        $qty = 1;
                        $rqty = '<span class="qty-calc" data-qty="'.$qty.'" data-profix="'.$query->prefix.'">'.$qty.'</span>';

                         if($query->prefix == "F" || $query->prefix == "B")
                         {
                            $qty = $query->total;
                            $rqty = '<span class="qty-calc" data-qty="'.$qty.'" data-profix="'.$query->prefix.'">'.$qty.'</span>';
                         }

                       if(isset($ArrPkrPrice[$query->opex_sku]))
                       {
                          $cost = $ArrPkrPrice[$query->opex_sku];
                       }

                       if(isset($ArrPkrPrice[$query->opex_sku]))
                       {
                          $utotal = (int)$ArrPkrPrice[$query->opex_sku] * (int)$qty;
                       }

                $html .= '<tr>
                    <td style="border:1px solid black;">'.$type.'</td>
                    <td style="border:1px solid black;">'.$query->c_at.'</td>
                    <td style="border:1px solid black;">'.$serial.'</td>
                    <td style="border:1px solid black;">'.$ord.'</td>
                    <td style="border:1px solid black;">'.$opex_sku.'</td>
                    <td style="border:1px solid black;">'.$query->title.'</td>
                    <td style="border:1px solid black;">'.$s.'</td>
                    <td style="border:1px solid black;">'.$rqty.'</td>
                     <td style="border:1px solid black;display:none;">'.$cost.'</td>
                      <td style="border:1px solid black;display:none;">'.$utotal.'</td>
                </tr>';



                $total += $qty;

                 if($query->prefix == "W" || $query->prefix=="M")
                {
                    $merchant_total += $qty;
                }

                if($query->prefix == "F")
                {
                     $fba_total += $qty;
                }
                if($query->prefix == "B"){
                    $bulk_total += $qty;
                }

            }
        }


        $html.='</tbody><tfoot>
        <tr><th colspan="7" style="text-align:right;    padding: 10px;">Total Merchant</th><th id="TotalQty">'.$merchant_total.'</tr>
        <tr><th colspan="7" style="text-align:right;    padding: 10px;">Total FBA</th><th id="TotalQty">'.$fba_total.'</tr>
         <tr><th colspan="7" style="text-align:right;    padding: 10px;">Total BULK</th><th id="TotalQty">'.$bulk_total.'</tr>
        <tr><th colspan="7" style="text-align:right;    padding: 10px;">Grand Total<br /><br /><br /><br /></th><th id="TotalQty">'.$total.'<br /><br /><br /><br /></th></tr>
        <tr>
        <th colspan="3" style="text-align:left;padding:5px;">Print Date : '.$today.'</th>
        <th colspan="4" style="text-align:right;padding:5px;">Print By : '.$user.'</th>
        </tr>
        <tr>
                    <th colspan="4" style="text-align:left;font-size:12px;font-weight:bold;">Drop-In Person Signature : _______________</th>
                    <th colspan="4" style="text-align:left;font-size:12px;font-weight:bold;">Supplier Signature : _______________</th>
                </tr>
        </tfoot>
        </table>';


        return $html;

        return "<script type='text/javascript'>


            var beforePrint = function() {
           console.log('Functionality to run before printing.');

        };
        var afterPrint = function() {
           console.log('Functionality to run after printing');
               window.close();
        };

        if (window.matchMedia) {
           var mediaQueryList = window.matchMedia('print');
           mediaQueryList.addListener(function(mql) {
               if (mql.matches) {
                   beforePrint();
               } else {
                   afterPrint();
               }
           });
        }

        window.onbeforeprint = beforePrint;
        window.onafterprint = afterPrint;

        window.print();
           </script>
           ";

   }

    public function PrintLabels(Request $r)
    {
        if(count($r->data) > 0)
        {
            $IdsArr = [];

            foreach($r->data as $d){$IdsArr[]=$d['id'];}

    //         $sql = DB::table('fba_pro_pr_items as f')
    // ->leftJoin('productitem as p', 'p.prodsku', '=', 'f.opex_sku')
    // ->leftJoin('suppliers as s', 's.supplierid', '=', 'f.supplier_id')
    // ->whereIn('f.id', $IdsArr)
    // // ->whereNull('f.last_hashtxt')
    // // ->orWhere('f.last_hashtxt', '=','')
    // ->whereRAw("f.last_hashtxt IS NULL OR f.last_hashtxt = ''")
    // ->select('f.id', 'f.pr_id','f.given_qty','f.received_qty', 'f.opex_sku', 'p.producttitle', 'p.productimage', 's.firstname', 's.lastname','f.supplier_id')
    // ->get();

    $sql = DB::table('fba_pro_pr_items as f')
    ->leftJoin('productitem as p', 'p.prodsku', '=', 'f.opex_sku')
    ->leftJoin('suppliers as s', 's.supplierid', '=', 'f.supplier_id')
    ->whereIn('f.id', $IdsArr)
    ->whereRaw("(f.last_hashtxt IS NULL OR f.last_hashtxt = '')")
    ->select([
        'f.id',
        'f.pr_id',
        'f.given_qty',
        'f.received_qty',
        'f.opex_sku',
        'p.producttitle',
        'p.productimage',
        's.firstname',
        's.lastname',
        'f.supplier_id'
    ])
    ->get();

            if($sql->count() > 0)
            {


                $hash_txt = Str::random(15);

                $hash_rnd = rand(1,100000000000);

                $hash_txt2 = Str::random(15);

                $main_hash = strtolower($hash_txt).$hash_rnd.$hash_txt2;

                $userid = Auth::user()->id;

                $msg = "";

                foreach($sql as $rx)
                {

                    $g = $rx->given_qty;

                    $r = $rx->received_qty;

                    $rm = (int)$g - (int)$r;

                    if($rm > 0)
                    {
                        //get details from here
                        for($i=1;$i<=$rm; $i++)
                        {

                        $Dsave = [
                            'dropin_type'=>4,
                            'sup_id'=>$rx->supplier_id,
                            'sup_name'=>$rx->firstname." ".$rx->lastname,
                            'order_number'=>$rx->id,
                            'ref_number'=>$rx->pr_id,
                            'opex_sku'=>$rx->opex_sku,
                            'title'=>$rx->producttitle,
                            'created_by'=>$userid,
                            'created_at'=>date('Y-m-d H:i:s'),
                            'hash'=>$main_hash,
                            'pr_id'=>$rx->pr_id,
                            'pr_item_id'=>$rx->id,
                            'expiry_date'=>date('Y-m-d H:i:s', strtotime(date('Y-m-d H:i:s') . ' +6 months'))
                          ];


                        $id = DB::table('new_drop_in_labels')->insertGetId($Dsave);

                        $barcode = 'H'.str_pad($id, 4, '0', STR_PAD_LEFT);

                        DB::table('new_drop_in_labels')->where('id',$id)->update(['barcode'=>$barcode,'prefix'=>'H','status'=>0]);

                        }

                        DB::table('fba_pro_pr_items')
                        ->where('id',$rx->id)
                        ->where('pr_id',$rx->pr_id)
                        ->whereRaw("(last_hashtxt IS NULL OR last_hashtxt = '')")
                        ->update([
                        'last_hashtxt'=>$main_hash,
                        'last_hashtxt_at'=>now()
                        ]);



                    }


                }


                return response()->json(['code'=>200,'msg'=>'barcode Generated Successfully!','hashtext'=>$main_hash]);



            }
            else
            {
                return response()->json(['code'=>404,'msg'=>'Sorry Something went wrong, Server not responding, please refresh the page and try again.']);
            }

        }
        else
        {
            return response()->json(['code'=>404,'msg'=>'Sorry Something went wrong, Server not responding, please refresh the page and try again.']);
        }

        // $id = date('ymdhisu');

        // $arr['id']= $id;

        // $arr['data']=$r->data;

        // $json_encode = json_encode($arr);

        // $path_new = storage_path() . "/reports/production_plan_supplier_labels_generated.json";

        // file_put_contents($path_new,$json_encode);

        // return response()->json(['code'=>200,'id'=>$id]);

    }

    public function GeneratedLabelsList(Request $r)
    {
        $sql = DB::table('new_drop_in_labels')
    ->select('id', 'barcode', 'sup_id', 'sup_name', 'opex_sku', 'title', 'prefix', 'created_at', 'expiry_date','status','hash')
    ->where('pr_item_id', '=', $r->id)
    ->get();


        if($sql->count() > 0)
        {
            $tr ='';
            foreach($sql as $rx)
            {
                $strs = "";
                $singlePrintBtn = '<span class="badge bg-danger">Restricted</span>';

                if($rx->status == 0)
                {

                    $strs = "<span class='badge bg-warning'>Pending</span>";
                    if(now() > $rx->expiry_date){
                        $strs = "<span class='badge bg-danger'>Expired</span>";
                        $singlePrintBtn = '<span class="badge bg-danger">Restricted</span>';
                    }else{
                        if (auth()->user()->can('single-print-view-barcode')) {

                       // $singlePrintBtn = '<a href="javascript:;" data-id="'.$rx->id.'" data-hash="'.$rx->hash.'" class="single-label-print"><i class="fa fa-print"></i> Print</a>';
                    $singlePrintBtn = '<span class="badge bg-danger">Restricted</span>';
                        }

                        }
                }

                if($rx->status == 1)
                {
                    $strs = "<span class='badge bg-success'>Drop-In</span>";
                }

                if($rx->status == 2)
                {
                    $strs = "<span class='badge bg-danger'>Cancelled</span>";
                }

                $tr.='<tr>
                        <td>'.$rx->title.'</td>
                        <td>'.$rx->opex_sku.'</td>
                        <td>'.$rx->sup_name.'</td>
                        <td>'.$rx->barcode.'</td>
                        <td>'.\Carbon\Carbon::parse($rx->created_at)->format('Y-m-d').'</td>
                         <td>'.\Carbon\Carbon::parse($rx->expiry_date)->format('Y-m-d').'</td>
                        <td>'.$strs.'</td>
                        <td>'.$singlePrintBtn.'</td>
                       </tr>';
            }

            $html ='<div style="padding:5px;"><table class="table table-bordered border-primary" id="PRItemsListPreview" style="">

                            <thead>
                                <tr>
                                    <th>Title</th>
                                    <th>SKU</th>
                                    <th>Supplier</th>
                                    <th>Barcode</th>
                                    <th>Created At</th>
                                    <th>Expiry At</th>
                                    <th>Status</th>
                                    <th>Print</th>
                                </tr>
                            </thead>

                            <tbody>


                           '.$tr.'



                            </tbody>

                     </table></div>';


            return response()->json(['code'=>200,'content'=>$html]);

        }
        else
        {
            return response()->json(['code'=>404,'content'=>'<div class="alert alert-danger">Record Not Found!</div>']);
        }
    }

    public function getLabelsId($hash)
    {

    //     $sssql = DB::table('fba_pro_purchase_request')
    // ->select('id')
    // ->where('is_nfmo',1)
    // ->get();
    //     $sqlArr = [];

    //     if($sssql->count() > 0)
    //     {

    //         foreach($sqlArr as $sar)
    //         {
    //             $sqlArr[$sar->id]=$sar->id;
    //         }

    //     }


        $prSql = DB::table('fba_pro_purchase_request as f')
    ->select('f.id','f.is_nfmo', 'f.order_type', 'oa.short_code','oa.country')
    ->leftJoin('opexpro_accounts as oa', 'oa.id', '=', 'f.store')
    ->whereIn('f.status', [1, 2, 3])
    ->get();

        $arr = [];

        foreach($prSql as $r)
        {
                        $ordType = "";

                        if($r->order_type == 1)
                        {
                            $ordType = "AIR Micro";
                        }
                        elseif($r->order_type == 2)
                        {
                            $ordType = "Bulk (For WH)";
                        }
                        elseif($r->order_type == 3)
                        {
                            $ordType = "SEA AWD";
                        }
                        elseif($r->order_type == 4)
                        {
                            $ordType = "AIR Cargo";
                        }
                        elseif($r->order_type == 5)
                        {
                            $ordType = "SEA FBA";
                        }



            $arr[$r->id]=$ordType." - ".$r->country." - ".$r->short_code;
        }

        $sql = DB::table('new_drop_in_labels')
    ->select('id','pr_id','saleorderid','order_number', 'barcode', 'sup_id', 'sup_name', 'opex_sku', 'title', 'prefix', 'created_at', 'expiry_date','s.suppliercode','is_cancelled_production_dropin')
    ->leftJoin('suppliers as s','s.supplierid','=','new_drop_in_labels.sup_id')
    ->where('hash',$hash)
    ->get();

        if($sql->count() > 0)
        {

          $html ='';

          $html.='<style>
          .single-label-body{
                 background-color: bisque;
                  min-height: 90vh;
                  width: 378px;
                  font-size: 14px;
          }
          .main-div{
              margin:0px !important;
              padding:0px !important;
          }

          .lbl-title{
          font-family: math;
                 font-weight: 600;
                 width: 100%;
                 text-align: left;
          }
          .label-header{

              margin-left: 25px;
              margin-right: 25px;
              padding-top:15px;
          }
          .prefix{
                  font-family: math;
                  font-weight: 600;
          }
          .opex-sku{
                 font-family: math;
                 font-weight: 600;
                 width: 68%;
                 text-align: left;
          }
          .opex-sku-title{
                 font-family: math;
                 font-weight: 600;
                 width: 100%;
                 text-align: left;
          }
          .dcodes {
              display:flex;
              justify-content: space-between;

          }
          .barcode-text{
              font-family: math;
              font-weight: 600;
              text-align:center;
              width:100%;

          }
          .opex-sku-title-two{
                 font-family: math;
                 width: 100%;
                 text-align: center;
                 font-size:12px;
                 font-weight:bold;
          }
          </style><div class="main-div">';

      foreach($sql as $d)
      {
              $skuNext = $d->opex_sku;

              $sCode = $d->suppliercode;

              if($d->saleorderid == 0)
              {
                $OrdTypeTxt = "PR : ".$d->pr_id;
              }
              else
              {
                $OrdTypeTxt = "M : ".$d->order_number;

                if($d->is_cancelled_production_dropin > 0)
                {
                    //$OrdTypeTxt = "<span style='font-size:10px;font-weight:bold;'>Cancel</span> : ".$d->order_number;

                    $sCode = $sCode." - PR : ".$d->pr_id;
                }


              }
              $topCode = isset($arr[$d->pr_id]) ? $arr[$d->pr_id] : '';

              $typeTextN = $topCode!="" ? explode("-",$topCode)[0]." - ".explode("-",$topCode)[1] : '';

              $accountTExtn =  $topCode!="" ? explode("-",$topCode)[2] : '';



                if($d->prefix=="M")
                {
                    $typeTextN = "SO";
                }

                if($d->is_cancelled_production_dropin > 0)
                {
                    $accountTExtn = "PO.Cancelled";
                }

              $code = $d->barcode;
            //working here for label
          // $barcode = DNS1DFacade::getBarcodePNG($code, 'UPCA', 2, 60);
          //$barcode = DNS1DFacade::getBarcodePNG($code, 'C128', 2, 30);
               // $barcode = DNS1DFacade::getBarcodePNG($code, 'C128',2,60);
        //  $barcode = DNS1DFacade::getBarcodePNG($code, 'C39',2,35);
        $barcode = DNS1DFacade::getBarcodePNG($code, 'C39',2,35);
            //$barcode = DNS1DFacade::getBarcodePNG($code, 'C128A',2,45);


            //valid
           // $barcode = DNS1DFacade::getBarcodePNG($code, 'C39',2,40);

        //   $barcode = DNS1DFacade::getBarcodePNG($code, 'C39',2,35); behtereen settings

              $html.='<div class="single-label-body">';

              $html.='<div class="label-header">


                   <div class="dcodes">
                    <div class="opex-sku" style="font-size: 25px;">'.$typeTextN.'</div>
                    <div class="prefix" style="font-size: 25px;">'.$accountTExtn.'</div>
                </div>

                <div class="barcode" style="margin-bottom:5px;">
                    <center>	<img src="data:image/png;base64,' .$barcode. '" style="" >
                    	<div class="barcode-text">'.$code.'</div></center>
                </div>


                <div class="dcodes">
                    <div class="opex-sku-title">'.$d->title.'</div>
                </div>

                <div class="dcodes">
                    <div class="opex-sku">'.$skuNext.'</div>
                    <div class="prefix">'.$OrdTypeTxt.'</div>
                </div>

                <div class="dcodes">
                    <div class="opex-sku">'.$sCode.'</div>
                    <div class="prefix">'.date('dmy',strtotime($d->created_at)).'-'.date('dmy',strtotime($d->expiry_date)).'</div>
                </div>


            </div>';

            $html.='</div>';




      }

      echo $html;

      echo "<script>window.print();</script>";

        }else{
            echo "<h1>Sorry Something Went Wrong, The Barcode Link has been expired!</h1>";
        }



    }

    public function BarcodeHandOverGatePass($hash)
    {

        $lastHashtxts = DB::table('fba_pro_pr_items')
    ->where('status', 2)
    ->where('supplier_id', $hash)
    ->whereDate('last_hashtxt_at', now()->toDateString())
    ->groupBy('last_hashtxt')
    ->get();

        $hArr = [];

        if($lastHashtxts->count() > 0)
        {
            foreach($lastHashtxts as $h)
            {
                $hArr[] = $h->last_hashtxt;
            }
        }


         $sql = DB::table('new_drop_in_labels')
    ->select(
        'new_drop_in_labels.id',
        'new_drop_in_labels.pr_id',
        'new_drop_in_labels.barcode',
        'new_drop_in_labels.sup_id',
        'new_drop_in_labels.sup_name',
        'new_drop_in_labels.opex_sku',
        'new_drop_in_labels.title',
        'new_drop_in_labels.prefix',
        'new_drop_in_labels.created_at',
        'new_drop_in_labels.expiry_date',
        's.suppliercode',
        DB::raw('COUNT(new_drop_in_labels.opex_sku) as total_barcodes')
    )
    ->leftJoin('suppliers as s', 's.supplierid', '=', 'new_drop_in_labels.sup_id')
    ->whereIn('new_drop_in_labels.hash', $hArr)
    ->groupBy('new_drop_in_labels.opex_sku')
    ->get();

        if($sql->count() > 0)
        {
            $tr='';
            $supName = '';
            $dateGen = '';
            $total = 0;
            foreach($sql as $r)
            {
                $supName  = $r->sup_name;
                $dateGen = $r->created_at;
                $total += $r->total_barcodes;
                $tr.='<tr>

                    <th style="border:1px solid black;">'.$r->pr_id.'</th>
                    <th style="border:1px solid black;">'.$r->title.'</th>
                    <th style="border:1px solid black;">'.$r->opex_sku.'</th>
                    <th style="border:1px solid black;">'.$r->total_barcodes.'</th>

                </tr>';
            }

            $html = '<table style="width:100%;border:1px solid black;border-collapse:collapse;font-family: system-ui;font-size: 16px;text-align:left;">

            <tr>
                <th colspan="4" style="border:1px solid black;text-align:center;font-size:25px;border-bottom:none;"><br />Barcode Labels Handover GatePass<br /><br /></th>
            </tr>

            <tr>
                <th style="">Supplier Name</th>
                <th style="">'.$supName.'</th>
                <th style="">Barcode Generated Date</th>
                <th style="">'.date('D, d M Y h:i: a',strtotime($dateGen)).'</th>
            </tr>
             <tr><th><br /><br /><br /><br /></th></tr>
            <tr>
                <th style="border:1px solid black;">PR Id#</th>
                <th style="border:1px solid black;">Title</th>
                <th style="border:1px solid black;">SKU</th>
                <th style="border:1px solid black;">Number Of Barcodes</th>
            </tr>

            '.$tr.'

            <tr>
                <th colspan="3" style="border:1px solid black;text-align:right;">Total</th>
                <th style="border:1px solid black;">'.$total.'</th>
            </tr>
            <tr><th><br /><br /><br /><br /></th></tr>
             <tr>

                <th style="">Provider Signature</th>
                <th style="">____________________</th>
                <th style="">Supplier Signature</th>
                <th style="">____________________</th>
            </tr>
            <tr>

                <th style="">Print Date</th>
                <th style="">'.date('D, d M Y h:i a').'</th>
            <tr><th><br /><br /></th></tr>
            </table>';

            echo $html;
             echo "<script>window.print();</script>";

        }else{
            echo "<h1>Sorry Somehting Went Wrong, Record Not Found!</h1>";
        }
    }

    public function AmazonBarcodeLabel($file_id,$box_id,$fnsku)
    {

        $sql = DB::table('fba_pro_shipment as f')
            ->leftJoin('productitem as p', 'p.prodsku', '=', 'f.opex_sku')
            ->select('f.fnsku', 'p.producttitle')
            ->where('f.file_id', '=', $file_id)
            ->where('f.box_id', '=', $box_id)
            ->where('f.fnsku', '=', $fnsku)
            ->limit(1)
            ->get();

        if($sql->count() > 0)
        {

        $rr = $sql->first();
        $code = $rr->fnsku;
        $title = $rr->producttitle;
        $barcode = DNS1DFacade::getBarcodePNG($code, 'C128',2,60);

        $html ='';

        // for($i=0;$i<=6;$i++)
        // {
        $html.= '
					<div style="position:relative;border:solid 0px; width:350px;font-family:Calibri;font-weight:600;height:95vh;margin:0 auto;margin-top:10px;">


						<div style="text-align:center;">
							<img  src="data:image/png;base64,' . $barcode . '" style="">
                            <div style="font-size:25px;">'.$fnsku.'</div>
                            <div style="font-size:20px;width:290px;margin-left:30px !important;margin-right:30px !important;word-wrap: break-word !important;">'.$title.'</div>
                            <div style="font-size:25px;"></div>
							<div style="clear:both;"></div>
						</div>

						<div style="clear:both;"></div>
					</div>
					<div style="clear:both;"></div>
                ';
        // }

        echo $html;

        echo "<script type='text/javascript'>


         var beforePrint = function() {
        console.log('Functionality to run before printing.');

    };
    var afterPrint = function() {
        console.log('Functionality to run after printing ".$fnsku."');
			window.close();

    };

    if (window.matchMedia) {
        var mediaQueryList = window.matchMedia('print');
        mediaQueryList.addListener(function(mql) {
            if (mql.matches) {
                beforePrint();
            } else {
                afterPrint();
            }
        });
    }

    window.onbeforeprint = beforePrint;
    window.onafterprint = afterPrint;

     window.print();
        </script>
        ";

}else{
    echo "Something Went Wrong, Fnsku Not found in Records.";
}

    }

    public function boxNumberLabelWithStore($file_id,$box_id)
    {

        $sql = DB::table('fba_pro_create_shipment as f')
    ->leftJoin('opexpro_accounts as oa', 'oa.id', '=', 'f.store_id')
    ->select('oa.short_code')
    ->where('f.id', '=', $file_id)
    ->get();

        if($sql->count() > 0)
        {

        $rr = $sql->first();

        $html = '
					<div style="position:relative;border:solid 0px; width:350px;font-family:Calibri;font-weight:600;height:95vh;margin:0 auto;">


						<div style="text-align:center;">
						<br />
                            <h1 style="font-size:100px;">#'.$box_id.' - '.$rr->short_code.'</h1>
						</div>

						<div style="clear:both;"></div>
					</div>
					<div style="clear:both;"></div>
                ';

        echo $html;

        echo "<script type='text/javascript'>


         var beforePrint = function() {
        console.log('Functionality to run before printing.');

    };
    var afterPrint = function() {
        console.log('Functionality to run after printing ');
			window.close();

    };

    if (window.matchMedia) {
        var mediaQueryList = window.matchMedia('print');
        mediaQueryList.addListener(function(mql) {
            if (mql.matches) {
                beforePrint();
            } else {
                afterPrint();
            }
        });
    }

    window.onbeforeprint = beforePrint;
    window.onafterprint = afterPrint;

     window.print();
        </script>
        ";

}else{
    echo "Something Went Wrong, Fnsku Not found in Records.";
}

    }

    public function GetSingleLabelById($id)
    {



        $prSql = DB::table('fba_pro_purchase_request as f')
    ->select('f.id', 'f.order_type', 'oa.short_code')
    ->leftJoin('opexpro_accounts as oa', 'oa.id', '=', 'f.store')
    ->whereIn('f.status', [1, 2])
    ->get();

        $arr = [];

        foreach($prSql as $r)
        {
            $ordType = "";

            if($r->order_type == 1)
            {
                $ordType = "FBA";
            }
            if($r->order_type == 2)
            {
                $ordType = "PP";
            }
            if($r->order_type == 3)
            {
                $ordType = "AWD";
            }

            $arr[$r->id]=$ordType.":".$r->short_code;
        }


        $sql = DB::table('new_drop_in_labels')
    ->select('dropin_type','id','pr_id', 'barcode', 'sup_id', 'sup_name', 'opex_sku', 'title', 'prefix', 'created_at', 'expiry_date','s.suppliercode')
    ->leftJoin('suppliers as s','s.supplierid','=','new_drop_in_labels.sup_id')
    ->where('new_drop_in_labels.id',$id)
    ->get();

        if($sql->count() > 0)
        {

          $html ='';

          $html.='<style>
          .single-label-body{
                 background-color: bisque;
                  min-height: 90vh;
                  width: 378px;
                  font-size: 14px;
          }
          .main-div{
              margin:0px !important;
              padding:0px !important;
          }

          .lbl-title{
          font-family: math;
                 font-weight: 600;
                 width: 100%;
                 text-align: left;
          }
          .label-header{

              margin-left: 25px;
              margin-right: 25px;
              padding-top:15px;
          }
          .prefix{
                  font-family: math;
                  font-weight: 600;
          }
          .opex-sku{
                 font-family: math;
                 font-weight: 600;
                 width: 68%;
                 text-align: left;
          }
          .opex-sku-title{
                 font-family: math;
                 font-weight: 600;
                 width: 100%;
                 text-align: left;
          }
          .dcodes {
              display:flex;
              justify-content: space-between;

          }
          .barcode-text{
              font-family: math;
              font-weight: 600;
              text-align:center;
              width:100%;

          }
          </style><div class="main-div">';

      foreach($sql as $d)
      {
              $OrdTypeTxt = isset($arr[$d->pr_id]) ? $arr[$d->pr_id]."-".$d->pr_id : 'PP-'.$d->pr_id;

              $code = $d->barcode;
                //log working here
              //$barcode = DNS1DFacade::getBarcodePNG($code, 'C128',2,60);
              //$barcode = DNS1DFacade::getBarcodePNG($code, 'C39', 2, 80);

              if($d->dropin_type==1 || $d->dropin_type==2)
            {
                $barcode = DNS1DFacade::getBarcodePNG($code, 'C128A',2,45);
            }
            else
            {
                //$barcode = DNS1DFacade::getBarcodePNG($code, 'C39', 2, 80);
                //$barcode = DNS1DFacade::getBarcodePNG($code, 'C128', 2, 40);
                $barcode = DNS1DFacade::getBarcodePNG($code, 'C39',2,35);
            }

              $html.='<div class="single-label-body">';

              $html.='<div class="label-header">



                <div class="barcode" style="margin-bottom:5px;">
                    <center>	<img src="data:image/png;base64,' .$barcode. '" style="margin-top: 5px !important;margin-bottom: 5px !important;" >
                    	<div class="barcode-text">'.$code.'</div></center>
                </div>


                <div class="dcodes">
                    <div class="opex-sku-title">'.$d->title.'</div>
                </div>

                <div class="dcodes">
                    <div class="opex-sku">'.$d->opex_sku.'</div>
                    <div class="prefix">'.$OrdTypeTxt.'</div>
                </div>
                <div class="dcodes">
                    <div class="opex-sku">'.$d->suppliercode.'</div>
                    <div class="prefix">'.date('dmy',strtotime($d->created_at)).'-'.date('dmy',strtotime($d->expiry_date)).'</div>
                </div>

            </div>';

            $html.='</div>';




      }

      echo $html;

       echo "<script>window.print();</script>";

        }else{
            echo "<h1>Sorry Something Went Wrong, The Barcode Link has been expired!</h1>";
        }



    }

    public function NewDropinScan()
    {
        $suppliers = DB::table('suppliers')
        ->select('supplierid', 'firstname', 'lastname')
        ->where('capicity', '>', 0)
        ->get();

        $data['sup'] = $suppliers;

        return view('supplychain.newdropinpage',$data);
    }

    public function NewDropinScanAction(Request $r)
    {
            $bars = trim($r->inpt);

            $bar = ucfirst($bars);

            $pSql = DB::table('new_drop_in_labels')
            ->select('id', 'opex_sku', 'sup_id', 'sup_name', 'title', 'created_at', 'expiry_date','status','scan_to_dropin_date','pr_id','pr_item_id','hash','barcode')
            ->where('barcode', $bar)
            ->where('prefix', 'H')
            ->whereIn('is_switched_cancelled',[0,5])
            ->get();

            if($pSql->count() == 0)
            {
              return response()->json(['code'=>404,'content'=>'<br /><div class="alert alert-danger">This <strong>'.$bar.'</strong> not found in Labels Records. This Label <strong>'.$bar.'</strong> Cancelled or Switched!</div>']);
            }

            if($pSql->count() > 0)
            {
                $pSqlRow = $pSql->first();

                $DropDbId = $pSqlRow->id;

                $CheckSupSusp = DB::table('suppliers')
                ->select('supplierid')
                ->where('supplierid', $pSqlRow->sup_id)
                ->where('is_suspended', 1)
                ->get();

                if($CheckSupSusp->count() > 0)
                {
                    return response()->json(['code'=>404,'content'=>'<br /><div class="alert alert-danger">Please don\'t process it because This Supplier Has Been Suspended and his Drop-In operation has been disabled by the system.</div>']);
                }

                $cDate = date('Y-m-d',strtotime($pSqlRow->created_at));

                $eDate = date('Y-m-d',strtotime($pSqlRow->expiry_date. ' + 90 days'));

                $nDate = date('Y-m-d');

                if($pSqlRow->status == 1)
                {
                    return response()->json(['code'=>404,'content'=>'<br /><div class="alert alert-danger">This <strong>'.$bar.'</strong> already scanned on <strong>'.$pSqlRow->scan_to_dropin_date.'</strong>.</div>']);
                }

                if($pSqlRow->status == 2)
                {
                    return response()->json(['code'=>404,'content'=>'<br /><div class="alert alert-danger">This <strong>'.$bar.'</strong> is <strong>Cancelled</strong> In The Records.</div>']);
                }

                if($nDate > $eDate)
                {
                    return response()->json(['code'=>404,'content'=>'<br /><div class="alert alert-danger">This <strong>'.$bar.'</strong> has been <strong>expired</strong> on <strong>'.$eDate.'</strong>.</div>']);
                }

                if($pSqlRow->status == 0)
                {
                    $pr_id = $pSqlRow->pr_id;

                    $pr_item_id = $pSqlRow->pr_item_id;

                    $ppSql = DB::table('fba_pro_pr_items')
                        ->select('id','pr_id', 'opex_sku', 'given_qty', 'received_qty','supplier_id','last_hashtxt','store_id')
                        ->whereRaw("status=2 AND id='$pr_item_id' AND received_qty < given_qty")
                        ->get();


                    if($ppSql->count() > 0)
                    {
                        $ppRow = $ppSql->first();

                        $prRecordid = $ppRow->id;

                        $StoreID = $ppRow->store_id;

                        $givenOrder=(int)$ppRow->given_qty;

                        $receivedOrder=(int)$ppRow->received_qty;

                        $remaining = $givenOrder - $receivedOrder;

                        if($remaining == 0 || $remaining < 0)
                        {
                            return response()->json(['code'=>404,'content'=>'<br /><div class="alert alert-danger">Sorry Supplier Order Quantity has been completed , Not found any remaing Order Quantity.</div>']);
                        }

                        if($remaining > 0)
                        {
                            DB::beginTransaction();

                            $user = Auth::user();

                            $userid = $user->id;

                            $username = $user->name;

                            $recLogId = DB::table('fba_pro_qty_received_log')->insertGetId([

                                'pr_id'=>$ppRow->pr_id,
                                'pr_item_id'=>$prRecordid,
                                'supplier_id'=>$ppRow->supplier_id,
                                'sku'=>$ppRow->opex_sku,
                                'received_qty'=>1,
                                'received_by'=>$userid,
                                'received_date'=>date('Y-m-d H:i:s'),
                                'hashtxt'=>$ppRow->last_hashtxt,
                                'barcode'=>$bar,
                                'store_id'=>$StoreID,
                                'last_received_qty'=>$receivedOrder,
                                'new_received_qty'=>($receivedOrder + 1)
                             ]);

                        $total = ($receivedOrder + 1);

                        $fba_item_id = $prRecordid;

                        $fba_pr_id = $ppRow->pr_id;

                        $fba_sup_id = $ppRow->supplier_id;

                        DB::table('fba_pro_pr_items')
                        ->where('id',$fba_item_id)
                        ->update([
                                'received_qty'=>$total,
                                'resp'=>'Drop-in by scan. '.json_encode(['last_qty'=>$ppRow->received_qty,'updated_qty'=>1,'total_sum'=>$total]),
                                'last_updated'=>date('Y-m-d H:i:s'),
                                'last_updated_by'=>$userid
                            ]);

                        DB::table('new_drop_in_labels')
                        ->where('id',$DropDbId)
                        ->where('barcode',$bar)
                        ->update([
                            'status'=>1,
                            'prev_qty'=>$receivedOrder,
                            'added_qty'=>1,
                            'sum_qty'=>$total,
                            'received_log_id'=>$recLogId,
                            'scan_to_dropin'=>1,
                            'scan_to_dropin_date'=>date('Y-m-d H:i:s'),
                            'scan_to_dropin_by'=>$userid,
                            'store_id'=>$StoreID
                            ]);

                            DB::table('pp_barcode_log')->insert([
                            'dropin_label_id'=>$pSqlRow->id,
                            'barcode'=>$bar,
                            'action'=>1,
                            'created_by'=>$userid,
                            'created_at'=>date('Y-m-d H:i:s')
                            ]);

                            DB::commit();


                        $pQuery = DB::table('productitem')->where('prodsku',$pSqlRow->opex_sku)->get();

                        $imgUrl = "#";

                        if($pQuery->count() > 0)
                        {
                             $imgUrl = $pQuery->first()->productimage;
                        }

                        $successMsg = "<p style='display:flex;justify-content:center;'><a  style='font-weight: bolder;' href='https://esirenext.com/opexerp/$imgUrl' class='fancybox' data-fancybox='gallery' data-caption='".$pSqlRow->title."'>
                     <img src='https://esirenext.com/opexerp/$imgUrl' style='width: 40%;' />
                        </a></p>";

                        $successMsg .= "<p>Item Drop-In Successfully.</p>";

                        $successMsg .= "<p>".$pSqlRow->title.", ".$pSqlRow->opex_sku."</p>";

                        $successMsg .= "<p>".$pSqlRow->sup_name."</p>";

                        $successMsg .= "<p>Quantity : 1</p>";

                        $successMsg .= "<p>Barcode : ".$bar."</p>";

                        $successMsg .= "<p>Receive Log Id : ".$recLogId."</p>";

                        $successMsg .= "<p>Order Given Quantity : ".$givenOrder."</p>";

                        $successMsg .= "<p>Received Quantity (till now) : ".$total."</p>";

                        $successMsg .= "<p>Date : ".date('Y-m-d h:i A')."</p>";



                        $barcodeUrl = url('lblsb/'.$pSqlRow->hash.'/'.$pSqlRow->barcode);

                        return response()->json(['code'=>200,'content'=>'<br /><div class="alert alert-success">'.$successMsg.'</div>','sup_id'=>$pSqlRow->sup_id,'barcode_url'=>$barcodeUrl]);

                    }

                }
                else
                {
                    return response()->json(['code'=>404,'content'=>'<br /><div class="alert alert-danger">Sorry Supplier Order Quantity has been completed , Not found any remaing Order Quantity!</div>']);
                }

                }






            }


    }

    public function NewDropInDatatable(Request $r)
    {
        $startDate = $r->start_date;

        $endDate = $r->end_date;

        $sup_id = $r->sup_id;

        // echo "<pre>";
        //     print_r($r->all());
        // echo "</pre>";

        $query = DB::table('new_drop_in_labels')
        ->select(['id','saleorderid','status','hub_received_status','is_return','sup_id','sup_name','title','prefix','received_log_id','hash','barcode','added_qty','order_number','opex_sku','title','pr_id','pr_item_id','is_sample_freez',DB::raw("DATE_FORMAT(scan_to_dropin_date,'%d-%m-%Y %h:%i %p') c_at,'1' as qty")])
        ->where('sup_id',$sup_id)
        ->where('is_manual','=',0)
        ->where('scan_to_dropin','=',1)
        // ->where('is_return','=',0)
        ->whereBetween(DB::raw("DATE_FORMAT(scan_to_dropin_date,'%Y-%m-%d')"), [$startDate, $endDate])
        ->get();

        return Datatables::of($query)
                     ->addIndexColumn()
                      ->addColumn('action', function ($q){

                        $sku = substr(strval($q->opex_sku), -1);

                        $arr=['Master','XS','S','M','L','XL','2XL','3XL'];

                        return isset($arr[$sku]) ? $arr[$sku] : '-';
                     })
                    ->addColumn('status_txt', function ($q){

                        $sts ='';

                        if($q->status == 0)
                        {
                             $sts ='<div class="badge bg-warning">Pending</div>';

                             if($q->is_return == 1)
                             {
                                 $sts ='<div class="badge bg-danger">Return</div>';
                             }
                        }

                        if($q->status == 1) //this
                        {
                             $sts ='<div class="badge bg-success">Active</div>';

                             if($q->is_return == 1)
                             {
                                 $sts .='<div class="badge bg-warning">Return item received again</div>';
                             }
                        }

                        if($q->status == 2)
                        {
                             $sts ='<div class="badge bg-danger">Cancelled</div>';
                        }

                        return $sts;
                     })
                     ->addColumn('revertbtn', function ($q){

                         if($q->hub_received_status==1)
                         {
                            return '<span class="badge bg-danger">Hub Received</span>';
                         }

                         if($q->is_return == 1 && $q->status == 0)
                         {
                            return '<span class="badge bg-danger">Returned</span>';
                         }

                         if($q->status == 1 && Auth::user()->id == 114)
                         {
                            return '<a href="javascript:;" data-id="'.$q->id.'" data-barcode="'.$q->barcode.'" class="badge bg-danger btn-remove-from-dropin"><i class="fa fa-trash"></i> Revert Item</a>';
                         }
                         else
                         {
                             return '-';
                         }





                     })
                    ->rawColumns(['action','status_txt','revertbtn'])
                    ->make(true);

    }

    public function ViewSkusForRemove(Request $r)
    {
        $opex_sku = $r->opex_sku;

        $sup_id = $r->sup_id;

        $startDate = $r->start_date;

        $endDate = $r->end_date;

        $result = DB::table('new_drop_in_labels as n')
    ->select([
        'n.id',
        'n.saleorderid',
        'n.sup_id',
        'n.sup_name',
        'n.title',
        'n.prefix',
        'n.received_log_id',
        'n.hash',
        'n.barcode',
        'n.added_qty',
        'n.order_number',
        'n.opex_sku',
        'n.pr_id',
        'n.pr_item_id',
        'n.is_sample_freez',
        'u.name',
        'n.hub_received_status',
        'n.status',
        'n.is_return'
    ])
    ->leftJoin('users as u', 'u.id', '=', 'n.created_by')
    ->where('n.sup_id', $sup_id)
    // ->where('n.status', 1)
    ->where('n.is_manual', 0)
    ->where('n.scan_to_dropin', 1)
    ->where('n.opex_sku', $opex_sku)
    ->whereBetween(DB::raw("DATE_FORMAT(n.created_at,'%Y-%m-%d')"), [$startDate, $endDate])
    ->get();

        $table = '<table class="table table-bordered border-primary">
                            <thead>
                                <tr>
                                    <th>S#</th>
                                    <th>PR Id#</th>
                                    <th>Drop-In Id#</th>
                                    <th>Drop-In By</th>
                                    <th>Product</th>
                                    <th>SKU</th>
                                    <th>Qty</th>
                                    <th>Status</th>
                                </tr>
                            </thead><tbody>';

        foreach($result as $k => $rx)
        {

            //$btn = '<a href="javascript:;" class="btn-remove-from-dropin" data-id="'.$rx->id.'" data-sup-id="'.$rx->sup_id.'" data-log-id="'.$rx->received_log_id.'" style="color:red;font-weight:bold;"><i class="fa fa-trash"></i> Remove</a>';

            $btn='';

            if($rx->status==1)
            {
                $btn ='<span class="badge bg-info">Active</span>';
            }

            if($rx->status==2)
            {
                $btn ='<span class="badge bg-secondary">Cancelled</span>';
            }

            if($rx->is_return==1)
            {
                $btn ='<span class="badge bg-danger">Return</span>';
            }

            if($rx->hub_received_status == 1)
            {
                $btn ='<span class="badge bg-success">Received In Hub</span>';
            }

            $table .= '

                                <tr>
                                    <td>'.($k + 1).'</td>
                                     <td>'.$rx->pr_id.'</td>
                                    <td>'.$rx->received_log_id.'</td>

                                    <td>'.$rx->name.'</td>
                                    <td>'.$rx->title.'</td>
                                    <td>'.$rx->opex_sku.'</td>
                                    <td>1</td>
                                    <th>'.$btn.'</th>
                                </tr>
                            ';

        }



        $table.='</tbody></table>';

        return response()->json(['code'=>200,'content'=>$table]);

    }

    public function RemoveDropinEntry(Request $r)
    {

        $userid = Auth::user()->id;

        $userinfo = DB::table('st_users')->where('id',$userid)->first();

        $id = $r->id;

        $barcode = $r->barcode;

        $sql = DB::table('new_drop_in_labels')
    ->select('id','received_log_id','pr_id','pr_item_id','sup_id','opex_sku','sup_name','title')
    ->where('id', $id)
    ->where('barcode', $barcode)
    ->where('is_return',0)
    ->where('hub_received_status',0)
    ->get();

        if($sql->count() > 0)
        {
            $row = $sql->first();

            $pr_id = $row->pr_id;

            $pr_item_id = $row->pr_item_id;

            $opex_sku = $row->opex_sku;

            $supid= $row->sup_id;

            $logid= $row->received_log_id;

            $FbaPro = DB::table('fba_pro_pr_items')
            ->selectRaw("id,pr_id,supplier_id,opex_sku,given_qty,received_qty,order_type")
            ->whereRaw("id='$pr_item_id' AND pr_id='$pr_id' AND supplier_id='$supid'")
            ->get();

            if($FbaPro->count() > 0)
            {
                $Frow = $FbaPro->first();

                $recvQty = (int)$Frow->received_qty;

                $NewReceive = $recvQty - 1;

                if($NewReceive < 0)
                {
                    $NewReceive = 0;
                }



               DB::table('fba_pro_qty_received_log')
               ->where('id',$logid)
               ->where('pr_id',$pr_id)
               ->where('pr_item_id',$pr_item_id)
               ->where('supplier_id',$supid)
               ->delete();

              DB::table('fba_pro_pr_items')->whereRaw("id='$pr_item_id' AND pr_id='$pr_id' AND opex_sku='$opex_sku' AND supplier_id='$supid'")->update([
                             'received_qty'=>$NewReceive,
                             'resp'=>json_encode(['last_qty'=>$recvQty,'updated_qty'=>1,'total_sum'=>$NewReceive]),
                             'last_updated'=>date('Y-m-d H:i:s'),
                             'last_updated_by'=>$userid
                ]);

                DB::table('new_drop_in_labels')
                ->where('id', $id)
                ->where('barcode', $barcode)
                ->update([
                    'status'=>0,
                    'prev_qty'=>0,
                    'added_qty'=>0,
                    'sum_qty'=>0,
                    'received_log_id'=>0,
                    'scan_to_dropin'=>0,
                    'scan_to_dropin_date'=>'0000:00:00 00:00:00',
                    'scan_to_dropin_by'=>0
                    ]);

                $title = "Item Removed From Drop-In List ".date('d-m-Y H:i:s');

			    $body = "<p>Dear Team</p>";

			    $body .= "<p>The Item <strong>Removed</strong> From <strong>Drop-In List</strong> by <strong>".$userinfo->fullname."</strong>.</p>";

			    $body .= "<p>PR ID: <strong>".$pr_id."</strong></p>";

			    $body .= "<p>PR Item Id: <strong>".$pr_item_id."</strong></p>";

			    $body .= "<p>Supplier: <strong>".$row->sup_name."</strong></p>";

			    $body .= "<p>Opex SKU: <strong>".$row->opex_sku."</strong></p>";

			    $body .= "<p>Product: <strong>".$row->title."</strong></p>";

			    $body .= "<p>Quantity: 1</p>";

			    $body .= "<p>Received Log Id Was: <strong>".$logid."</strong></p>";

			    $body .= "<p>Thanks</p>";

    			$details = [
                'title' => $title,
                'body' => $body,
                ];

                $subject = $title;
                //,'walayatkhan.esire@gmail.com','umarmalik.esire@gmail.com'
                $recipients = ['bcc.mailnotifications@gmail.com','abdulrehman.esire@gmail.com','walayatkhan.esire@gmail.com','umarmalik.esire@gmail.com'];

                Mail::to($recipients)->send(new CustMail($details,$subject));

                return response()->json(['code'=>200,'msg'=>'Drop-In Entry Removed Successfully!']);

            }
            else
            {

                return response()->json(['code'=>404,'msg'=>'Sorry Records not found in Production Plan Records.']);

            }


        }else
        {
            return response()->json(['code'=>404,'msg'=>'Sorry Record not found in Drop-In Records.']);
        }

    }

    //added by Abdul Rehman JR - 2024-01-06
    public function pendingForHub(){
        $query = DB::table('suppliers_new')->select('supplier_name','id')->get();
        return view('supplychain.pendinghub',compact('query'));
    }

    public function pendingForHubDt(Request $request){

        $query = DB::table('new_drop_in_labels')
        ->selectRaw('id,opex_sku,title,is_packed,sup_name,pr_id,barcode,added_qty,"1" as total')
        ->where('hub_received_status',0)
        ->where('scan_to_dropin',1)
        ->where('status',1)
        ->where('is_return',0)
        // ->where('is_packed',0)
         ->where('sup_id','!=',40)
        ->orderBy('sup_name','ASC');

        if($request->supplier != ''){
            $query->where('sup_id','=',$request->supplier);
        }

        return DataTables::of($query)
         ->addColumn('action', function($query) {
            $html = "<button class='btn btn-info btn-sm' data-id='".$query->opex_sku."' id='pendingLabelBtn'><i class='far fa-eye'></i></button>";
            return $html;
        })
        ->addColumn('size', function($query) {
            $title = $query->title;
            $check = explode('|',$title);
            return $check[1] ?? '';
        })
        ->addColumn('packed', function($query) {
            return $query->is_packed == 1 ? 'Packed' : 'InQC';
        })
        ->rawColumns(['action','size','packed'])
        ->addIndexColumn()
        ->make(true);
    }

    public function viewPendingLabel($opex_sku){
        try{
        $query = DB::table('new_drop_in_labels')
        ->selectRaw('id,opex_sku,title,sup_name,barcode,added_qty,status,scan_to_dropin_date')
        ->where('hub_received_status',0)
        ->where('scan_to_dropin',1)
        ->where('status',1)
        ->where('opex_sku',$opex_sku)
        ->get();


        if($query->count() > 0){
            $html = '';

           foreach($query as $row){
               $html .= '<tr>';
               $html .= '<td>'.$row->id.'</td>';
               $html .= '<td>'.$row->scan_to_dropin_date.'</td>';
               $html .= '<td>'.$row->sup_name.'</td>';
               $html .= '<td>'.$row->barcode.'</td>';
               $html .= '<td>'.$row->added_qty.'</td>';
               $html .= '<td><span class="badge bg-info">Active</span></td>';
               $html .= '<tr>';
           }
           return response()->json([
                'status' => true,
                'data' => $html
            ]);
        }
        else{
            return response()->json([
                'status' => false,
                'data' => 'No label found!'
            ]);
        }
     }catch(Exception $e){
          return response()->json([
                'status' => false,
                'data' => $e->message()
            ]);
     }
    }

    public function labelLogExcel($status){
        return Excel::download(new LabelLogExcel($status), 'Pending_For_Hub_Log.xlsx');
    }

    public function newMerchantScantoDropin()
    {
         $suppliers = DB::table('suppliers')
        ->select('supplierid', 'firstname', 'lastname')
        ->where('capicity', '>', 0)
        ->get();

        $data['sup'] = $suppliers;

        return view('supplychain.nmodropin',$data);
    }
    public function contentError($message)
    {
             $html ='<br /><div class="alert alert-danger">';
             $html .='<p>'.$message.'</p>';
             $html .='</div>';
             return response()->json(['code'=>404,'content'=>$html]);
    }
    public function NdropInAction(Request $r)
    {

         $inpSupid = $r->supid;

         $saleorderid = trim($r->inpt);

         $hash_txt = Str::random(15);

         $hash_rnd = rand(1,100000000000);

         $hash_txt2 = Str::random(15);

         $main_hash = strtolower($hash_txt).$hash_rnd.$hash_txt2;

         $uInfo = Auth::user();

         $userid = $uInfo->id;

         $userName = $uInfo->name;

         $ch = DB::table('saleorders as s')
               ->selectRaw("gnp.id as gnp_id,gnp.batch_number,gnp.supplier_id,s.saleorderid,s.order_number,s.reference_no,s.supplier,s.status,s.is_sample_order,s.sample_order_for,s.order_sku,s.product_title")
               ->leftJoin('generate_new_po as gnp','gnp.saleorderid','=','s.saleorderid')
               ->whereRaw("gnp.supplier_id='$inpSupid' AND s.item_supplier_status=1 AND gnp.dropin_status=0 AND gnp.is_cancelled=0 AND gnp.cancellation_by=0 AND s.saleorderid='".$saleorderid."' AND s.status IN ('In Process','Hold-On')")
               ->get();

         if($ch->count() > 0)
         {
             $rza = $ch->first();

             $isSampleFreez = 0;

             if($rza->is_sample_order == 1)
             {
                $isSampleFreez = 1;
             }

             $supIdNew = $rza->supplier_id;

             $ItemSupplierStatus = 7;

             $OrderStatus=$rza->status;

             $typeLabel = ['','W','M','F','B'];

             $Dsave = [
                      'gnp_id'=>$rza->gnp_id,
                      'saleorderid'=>$rza->saleorderid,
                      'dropin_type'=>2,
                      'sup_id'=>$rza->supplier_id,
                      'sup_name'=>$rza->supplier,
                      'order_number'=>$rza->order_number,
                      'ref_number'=>$rza->reference_no,
                      'opex_sku'=>$rza->order_sku,
                      'title'=>$rza->product_title,
                      'created_by'=>$userid,
                      'created_at'=>date('Y-m-d H:i:s'),
                      'hash'=>$main_hash,
                      'is_sample_freez'=>$isSampleFreez
                      ];

            DB::beginTransaction();

                 DB::table('generate_new_po')
                      ->where('order_number',$rza->order_number)
                      ->where('ref_number',$rza->reference_no)
                      ->where('supplier_id',$supIdNew)
                      ->update([
                          'dropin_status'=>1,
                          'dropin_date'=>date('Y-m-d H:i:s'),
                          'dropin_by'=>$userid
                          ]);

                 DB::table('saleorders')
                 ->where('saleorderid','=',$saleorderid)
                 ->update([
                        'item_supplier_status'=>$ItemSupplierStatus,
                        'is_sample_freez'=>$isSampleFreez
                 ]);





                  $id = DB::table('new_drop_in_labels')->insertGetId($Dsave);

                  $barcode = $typeLabel[2].str_pad($id, 4, '0', STR_PAD_LEFT);

                  DB::table('new_drop_in_labels')->where('id',$id)->update([
                      'barcode'=>$barcode,
                      'prefix'=>$typeLabel[2],
                      'status'=>1
                      ]);


                  $ch_ord_q = DB::table('new_drop_in_labels')
                 ->where('saleorderid',$rza->saleorderid)
                 ->where('order_number',$rza->order_number)
                 ->where('barcode','!=',$barcode)
                 ->where('status',1)
                 ->get();

                  if($ch_ord_q->count() > 0)
                  {

                    DB::table('new_drop_in_labels')
                    ->where('saleorderid',$rza->saleorderid)
                    ->where('order_number',$rza->order_number)
                    ->where('barcode','!=',$barcode)
                    ->limit(1)
                    ->update([
                        'status'=>2,
                        'cancellation_at'=>date('Y-m-d H:i:s'),
                        'cancellled_by'=>'74'.$userid
                    ]);

                  }

                  $loginfo = '<p>Order <strong>'.$rza->order_number.'</strong> assigned to <strong>'.$rza->supplier.'</strong> and dropped in warehouse via scanning <strong>Supplier Order Processing Slip '.$rza->saleorderid.'</strong>.Here is a Barcode <strong>'.$barcode.'</strong> generated against this order.</p>';

                  $loginserted = DB::table('merchantorderlog')->insertGetId([
				      	'logdate' => date('Y-m-d H:i:s'),
						'logtimestamp' => time(),
						'ordernumber' =>$rza->order_number,
						'orderdbid' =>$rza->saleorderid,
						'logdetail' => $loginfo,
						'loguser' => $userid
				      ]);

            DB::commit();

             if($rza->is_sample_order == 1)
            {
                      $titleEmail = 'Sample Order #'.$rza->order_number.' - '.$rza->sample_order_for.' Received In Warehouse '.date('d-m-Y');

                      $messageBody = '<p>Dear Team</p>';

                      $messageBody .= '<p>The Sample Order <strpmg>#'.$rza->order_number.'</strong> Has been Received In Warehouse from Supplier.</p>';

                      $messageBody .= '<p><strong>'.$rza->product_title.' | '.$rza->order_sku.'</strong></p>';

                      $messageBody .= '<p>Thanks</p>';

                      $details = [
                        'title' => $titleEmail,
                        'body' => $messageBody,
                      ];

                    $subject = $titleEmail;

                    $recipients = ['bcc.mailnotifications@gmail.com','subhanshah.esire@gmail.com','abdulrehmanshahzad.esire@gmail.com','raja.esire@gmail.com'];

                    Mail::to($recipients)->send(new CustMail($details,$subject));
            }

            $html  = '<br /><div class="alert alert-success">';
            $html .= '<p style="margin: 5px;font-size: 15px;font-style: italic;font-weight: 500;">The Item Dropped in Warehouse Successfully!</p>';
            $html .= '<p style="margin: 5px;font-size: 15px;font-style: italic;font-weight: 500;">Item : '.$rza->product_title.'</p>';
            $html .= '<p style="margin: 5px;font-size: 15px;font-style: italic;font-weight: 500;">Sku : '.$rza->order_sku.'</p>';
            $html .= '<p style="margin: 5px;font-size: 15px;font-style: italic;font-weight: 500;">Order Number : '.$rza->order_number.'</p>';
            $html .= '<p style="margin: 5px;font-size: 15px;font-style: italic;font-weight: 500;">Batch Number : '.$rza->batch_number.'</p>';
            $html .= '<p style="margin: 5px;font-size: 15px;font-style: italic;font-weight: 500;">Barcode : '.$rza->saleorderid.'</p>';
            $html .= '<p style="margin: 5px;font-size: 15px;font-style: italic;font-weight: 500;">Reference Number : '.$rza->reference_no.'</p>';
            $html .= '<p style="margin: 5px;font-size: 15px;font-style: italic;font-weight: 500;">Supplier : '.$rza->supplier.'</p>';
            $html .= '<p style="margin: 5px;font-size: 15px;font-style: italic;font-weight: 500;">Slip : #'.$rza->saleorderid.'</p>';
            $html .= '<p style="margin: 5px;font-size: 15px;font-style: italic;font-weight: 500;">Date : #'.date('d-m-Y h:i A').'</p>';
            $html .= '<p style="margin: 5px;font-size: 15px;font-style: italic;font-weight: 500;">Barcode Generated : #'.$barcode.'</p>';
            $html .= '<p style="margin: 5px;font-size: 15px;font-style: italic;font-weight: 500;">Drop-in By : '.$userName.'</p>';
            $html .= '</div>';

            $LabelUrl = url('genbarcode/'.$main_hash);

            return response()->json([
                'code'=>200,
                'content'=>$html,
                'label_url'=>$LabelUrl,
                'sup_id'=>$rza->supplier_id
            ]);


         }
         else
         {
            return $this->contentError("Sorry, the record was not found. Only orders with the statuses <strong>In Process, Hold-On, or Cancelled</strong> are allowed for drop-in.");
         }
    }

    public function cancelledproductiondropin()
    {
        $suppliers = DB::table('suppliers')
        ->select('supplierid', 'firstname', 'lastname')
        ->where('is_active','=',1)
        ->where('supptype','=',1)
        ->where('capicity', '>', 0)
        ->get();

        $data['sup'] = $suppliers;

        return view('supplychain.canceldropin',$data);
    }

    public function DatatableCancelDropIn(Request $r)
    {
       $sql = DB::table('generate_new_po as g')
    ->select(
        'g.saleorderid',
        'g.order_number',
        'g.ref_number',
        'g.opex_sku',
        'g.supplier_id',
        'g.po_date',
        DB::raw("CONCAT(s.firstname, ' ', s.lastname) as sup_name,DATE_FORMAT(g.po_date, '%Y-%m-%d') podate"),
        'p.producttitle',
        'cancelleation_at'
    )
    ->leftJoin('suppliers as s', 's.supplierid', '=', 'g.supplier_id')
    ->leftJoin('productitem as p', 'p.prodsku', '=', 'g.opex_sku')
    ->where('g.supplier_id', $r->sup_id)
    ->where('g.supplier_id','!=', 0)
    ->where('g.dropin_status', 0)
    ->where('g.is_cancelled', 1)
    ->where('g.is_cancelled_production_dropin', 0)
    ->whereBetween(DB::raw("DATE_FORMAT(g.po_date, '%Y-%m-%d')"), [
        DB::raw("DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 60 DAY), '%Y-%m-%d')"),
        DB::raw("DATE_FORMAT(NOW(), '%Y-%m-%d')")
    ])
    ->groupBy('g.saleorderid')
    ->orderBy('g.po_date','DESC');

    if($r->start_date != '' && $r->end_date != ''){
        $sql->whereBetween(DB::raw("DATE_FORMAT(cancelleation_at,'%Y-%m-%d')"), [$r->start_date, $r->end_date]);
    }
    else{
        $sql->whereDate('cancelleation_at','=',now());
    }

    $sql = $sql->get();


        return DataTables::of($sql)
         ->addColumn('action', function($query) {
            $html = "<button class='btn btn-primary btn-sm btn-drop-in-cancelled' data-id='".$query->saleorderid."' data-sup-id='".$query->supplier_id."' id=''>Drop-In</button>";
            return $html;
        })
        // ->addColumn('size', function($query) {
        //     $title = $query->title;
        //     $check = explode('|',$title);
        //     return $check[1];
        // })
        // ->addColumn('packed', function($query) {
        //     return $query->is_packed == 1 ? 'Packed' : 'InQC';
        // })
        ->rawColumns(['action'])
        ->addIndexColumn()
        ->make(true);
    }

    public function cancelDropinAct(Request $rr)
    {
        $sid = $rr->saleorderid;

        $supid = $rr->supid;

        $u = Auth::user();

        $sql = DB::table('generate_new_po as g')
    ->select(
        'g.saleorderid',
        'g.order_number',
        'g.ref_number',
        'g.opex_sku',
        'g.po_date',
        'g.supplier_id',
        DB::raw("CONCAT(s.firstname, ' ', s.lastname) as sup_name"),
        'p.producttitle'
    )
    ->leftJoin('suppliers as s', 's.supplierid', '=', 'g.supplier_id')
    ->leftJoin('productitem as p', 'p.prodsku', '=', 'g.opex_sku')
    ->where('g.saleorderid', $sid)
    ->where('g.supplier_id', $supid)
    ->where('g.dropin_status', 0)
    ->where('g.is_cancelled', 1)
    ->where('g.is_cancelled_production_dropin', 0)
    ->groupBy('g.saleorderid')
    ->get();

        if($sql->count() > 0)
        {




            $r = $sql->first();

            $chk_n = DB::table('generate_new_po')
            ->where('saleorderid',$sid)
            ->where('supplier_id',$supid)
            ->where('is_cancelled_production_dropin',1)
            ->get();

            if($chk_n->count() > 0)
            {
                return response()->json(['code'=>404,'msg'=>'Something Went Wrong,Please Check Order log, The item already drop-in','hashtext'=>'']);
            }


            $pr_id = 240;

            $hash_txt = Str::random(15);

            $hash_rnd = rand(1,100000000000);

            $hash_txt2 = Str::random(15);

            $main_hash = strtolower($hash_txt).$hash_rnd.$hash_txt2;

            $new_main_hash = $main_hash."-".$pr_id."-".$supid."-".$sid;

            $nPrData = [
                'pr_id' => $pr_id,
                'order_type' => 2,
                'opex_sku' => $r->opex_sku,
                'given_qty' => 1,
                'supplier_id' => $r->supplier_id,
                'assign_date' => now(),
                'received_qty' => 1,
                'status' => 3,
                'created_by' =>$u->id,
                'created_at' =>now(),
                'approved_by' =>$u->id,
                'safety_order_number' =>$r->order_number,
                'store_id' => 10,
                'is_cancelled_drop_in' => 1,
                'is_cancelled_drop_in_at' => now(),
                'is_cancelled_drop_in_by' => $u->id,
                'is_cancelled_drop_in_saleorderid' => $sid,
                'last_hashtxt'=>$new_main_hash
            ];

            DB::beginTransaction();

                $pr_item_id = DB::table('fba_pro_pr_items')->insertGetId($nPrData);

                $Dsave = [
                                'saleorderid'=>$sid,
                                'dropin_type'=>4,
                                'sup_id'=>$r->supplier_id,
                                'sup_name'=>$r->sup_name,
                                'order_number'=>$r->order_number."-CPO",
                                'ref_number'=>$r->ref_number,
                                'opex_sku'=>$r->opex_sku,
                                'title'=>$r->producttitle,
                                'created_by'=>$u->id,
                                'created_at'=>now(),
                                'hash'=>$new_main_hash,
                                'pr_id'=>$pr_id,
                                'pr_item_id'=>$pr_item_id,
                                'scan_to_dropin'=>1,
                                'expiry_date'=>date('Y-m-d H:i:s', strtotime(date('Y-m-d H:i:s') . ' +6 months')),
                                'scan_to_dropin_date'=>now(),
                                'scan_to_dropin_by'=>$u->id,
                                'is_cancelled_production_dropin'=>1
                              ];


                $id = DB::table('new_drop_in_labels')->insertGetId($Dsave);

                $barcode = 'H'.str_pad($id, 4, '0', STR_PAD_LEFT);

                DB::table('new_drop_in_labels')->where('id',$id)->update(['barcode'=>$barcode,'prefix'=>'H','status'=>1]);

                DB::table('generate_new_po')
                      ->where('saleorderid',$sid)
                      ->where('supplier_id',$r->supplier_id)
                      ->update([
                          'dropin_status'=>1,
                          'dropin_date'=>date('Y-m-d H:i:s'),
                          'dropin_by'=>$u->id,
                          'is_cancelled_production_dropin'=>1
                      ]);

                $loginfo = "Cancelled Supplier Order <strong>#".$r->order_number."</strong> Drop-in By <strong>".$u->name."</strong> from ".$r->sup_name." using cancelled drop-in page. The item drop-in as Hub Item with barcode <strong>".$barcode."</strong>";

                DB::table('merchantorderlog')->insertGetId([
    				      	'logdate' => now(),
    						'logtimestamp' => time(),
    						'ordernumber' =>$r->order_number,
    						'orderdbid' =>$sid,
    						'logdetail' => $loginfo,
    						'loguser' => $u->id
    			]);

         DB::commit();

            return response()->json(['code'=>200,'msg'=>'Cancelled Item Drop-in Successfully!','hashtext'=>$new_main_hash]);

        }
        else
        {
            return response()->json(['code'=>404,'msg'=>'Something Went Wrong, Order id not found in records!','hashtext'=>'']);
        }



    }



    // public function getLabelsIdSingleBarcode($hash,$barcode)
    // {

    // //     $sssql = DB::table('fba_pro_purchase_request')
    // // ->select('id')
    // // ->where('is_nfmo',1)
    // // ->get();
    // //     $sqlArr = [];

    // //     if($sssql->count() > 0)
    // //     {

    // //         foreach($sqlArr as $sar)
    // //         {
    // //             $sqlArr[$sar->id]=$sar->id;
    // //         }

    // //     }


    //     $prSql = DB::table('fba_pro_purchase_request as f')
    // ->select('f.id','f.is_nfmo', 'f.order_type', 'oa.short_code','oa.country')
    // ->leftJoin('opexpro_accounts as oa', 'oa.id', '=', 'f.store')
    // ->whereIn('f.status', [1, 2])
    // ->get();

    //     $arr = [];

    //     foreach($prSql as $r)
    //     {
    //         $ordType = "";

    //         if($r->order_type == 1)
    //         {
    //             $ordType = "FBA";
    //         }
    //         if($r->order_type == 2)
    //         {
    //             $OrdTypeTxtXZ = $r->is_nfmo==1 ? 'FBA' : 'PP';

    //             $ordType = $OrdTypeTxtXZ;
    //         }
    //         if($r->order_type == 3)
    //         {
    //             $ordType = "AWD";
    //         }

    //         $arr[$r->id]=$ordType.":".$r->country.":".$r->short_code;
    //     }

    //     $sql = DB::table('new_drop_in_labels')
    // ->select('id','pr_id','saleorderid','order_number', 'barcode', 'sup_id', 'sup_name', 'opex_sku', 'title', 'prefix', 'created_at', 'expiry_date','s.suppliercode')
    // ->leftJoin('suppliers as s','s.supplierid','=','new_drop_in_labels.sup_id')
    // ->where('barcode',$barcode)
    // ->where('hash',$hash)
    // ->get();

    //     if($sql->count() > 0)
    //     {

    //       $html ='';

    //       $html.='<style>
    //       .single-label-body{
    //              background-color: bisque;
    //               min-height: 90vh;
    //               width: 378px;
    //               font-size: 14px;
    //       }
    //       .main-div{
    //           margin:0px !important;
    //           padding:0px !important;
    //       }

    //       .lbl-title{
    //       font-family: math;
    //              font-weight: 600;
    //              width: 100%;
    //              text-align: left;
    //       }
    //       .label-header{

    //           margin-left: 25px;
    //           margin-right: 25px;
    //           padding-top:15px;
    //       }
    //       .prefix{
    //               font-family: math;
    //               font-weight: 600;
    //       }
    //       .opex-sku{
    //              font-family: math;
    //              font-weight: 600;
    //              width: 68%;
    //              text-align: left;
    //       }
    //       .opex-sku-title{
    //              font-family: math;
    //              font-weight: 600;
    //              width: 100%;
    //              text-align: left;
    //       }
    //       .dcodes {
    //           display:flex;
    //           justify-content: space-between;

    //       }
    //       .barcode-text{
    //           font-family: math;
    //           font-weight: 600;
    //           text-align:center;
    //           width:100%;

    //       }
    //       .opex-sku-title-two{
    //              font-family: math;
    //              width: 100%;
    //              text-align: center;
    //              font-size:12px;
    //              font-weight:bold;
    //       }
    //       </style><div class="main-div">';

    //   foreach($sql as $d)
    //   {
    //           if($d->saleorderid == 0)
    //           {
    //             $OrdTypeTxt = isset($arr[$d->pr_id]) ? $arr[$d->pr_id]."-".$d->pr_id : 'PP-'.$d->pr_id;
    //           }
    //           else
    //           {
    //             $OrdTypeTxt = "M-".$d->order_number;
    //           }
    //           $topCode = isset($arr[$d->pr_id]) ? $arr[$d->pr_id] : '';

    //           $typeTextN = $topCode!="" ? explode(":",$topCode)[0].":".explode(":",$topCode)[1] : '';

    //           $accountTExtn =  $topCode!="" ? explode(":",$topCode)[2] : '';

    //           $code = $d->barcode;
    //         //working here for label
    //       // $barcode = DNS1DFacade::getBarcodePNG($code, 'UPCA', 2, 60);
    //       //$barcode = DNS1DFacade::getBarcodePNG($code, 'C128', 2, 30);
    //           // $barcode = DNS1DFacade::getBarcodePNG($code, 'C128',2,60);
    //     //  $barcode = DNS1DFacade::getBarcodePNG($code, 'C39',2,35);
    //     $barcode = DNS1DFacade::getBarcodePNG($code, 'C39',2,35);
    //         //$barcode = DNS1DFacade::getBarcodePNG($code, 'C128A',2,45);


    //         //valid
    //       // $barcode = DNS1DFacade::getBarcodePNG($code, 'C39',2,40);

    //     //   $barcode = DNS1DFacade::getBarcodePNG($code, 'C39',2,35); behtereen settings

    //           $html.='<div class="single-label-body">';

    //           $html.='<div class="label-header">




    //             <div class="barcode" style="margin-bottom:5px;">
    //                 <center>	<img src="data:image/png;base64,' .$barcode. '" style="" >
    //                 	<div class="barcode-text">'.$code.'</div></center>
    //             </div>


    //             <div class="dcodes">
    //                 <div class="opex-sku-title">'.$d->title.'</div>
    //             </div>

    //             <div class="dcodes">
    //                 <div class="opex-sku">'.$d->opex_sku.'</div>
    //                 <div class="prefix">'.$OrdTypeTxt.'</div>
    //             </div>
    //             <div class="dcodes">
    //                 <div class="opex-sku">'.$d->suppliercode.'</div>
    //                 <div class="prefix">'.date('dmy',strtotime($d->created_at)).'-'.date('dmy',strtotime($d->expiry_date)).'</div>
    //             </div>
    //             <div class="dcodes">
    //                 <div class="opex-sku-title-two" style="font-size:25px;">'.$typeTextN.' - '.$accountTExtn.'</div>
    //             </div>

    //         </div>';

    //         $html.='</div>';




    //   }

    //   echo $html;

    //   echo "<script>window.print();</script>";

    //     }else{
    //         echo "<h1>Sorry Something Went Wrong, The Barcode Link has been expired!</h1>";
    //     }



    // }
    public function getLabelsIdSingleBarcode($hash,$barcode)
    {

    //     $sssql = DB::table('fba_pro_purchase_request')
    // ->select('id')
    // ->where('is_nfmo',1)
    // ->get();
    //     $sqlArr = [];

    //     if($sssql->count() > 0)
    //     {

    //         foreach($sqlArr as $sar)
    //         {
    //             $sqlArr[$sar->id]=$sar->id;
    //         }

    //     }


        $prSql = DB::table('fba_pro_purchase_request as f')
    ->select('f.id','f.is_nfmo', 'f.order_type', 'oa.short_code','oa.country')
    ->leftJoin('opexpro_accounts as oa', 'oa.id', '=', 'f.store')
    ->whereIn('f.status', [1, 2, 3])
    ->get();

        $arr = [];

        foreach($prSql as $r)
        {
                        $ordType = "";

                        if($r->order_type == 1)
                        {
                            $ordType = "AIR Micro";
                        }
                        elseif($r->order_type == 2)
                        {
                            $ordType = "Bulk (For WH)";
                        }
                        elseif($r->order_type == 3)
                        {
                            $ordType = "SEA AWD";
                        }
                        elseif($r->order_type == 4)
                        {
                            $ordType = "AIR Cargo";
                        }
                        elseif($r->order_type == 5)
                        {
                            $ordType = "SEA FBA";
                        }

            $arr[$r->id]=$ordType." - ".$r->country." - ".$r->short_code;
        }

        $sql = DB::table('new_drop_in_labels')
    ->select('id','pr_id','saleorderid','order_number', 'barcode', 'sup_id', 'sup_name', 'opex_sku', 'title', 'prefix', 'created_at', 'expiry_date','s.suppliercode','is_cancelled_production_dropin')
    ->leftJoin('suppliers as s','s.supplierid','=','new_drop_in_labels.sup_id')
    ->where('hash',$hash)
    ->where('barcode',$barcode)
    ->get();

        if($sql->count() > 0)
        {

          $html ='';

          $html.='<style>
          .single-label-body{
                 background-color: bisque;
                  min-height: 90vh;
                  width: 378px;
                  font-size: 14px;
          }
          .main-div{
              margin:0px !important;
              padding:0px !important;
          }

          .lbl-title{
          font-family: math;
                 font-weight: 600;
                 width: 100%;
                 text-align: left;
          }
          .label-header{

              margin-left: 25px;
              margin-right: 25px;
              padding-top:15px;
          }
          .prefix{
                  font-family: math;
                  font-weight: 600;
          }
          .opex-sku{
                 font-family: math;
                 font-weight: 600;
                 width: 68%;
                 text-align: left;
          }
          .opex-sku-title{
                 font-family: math;
                 font-weight: 600;
                 width: 100%;
                 text-align: left;
          }
          .dcodes {
              display:flex;
              justify-content: space-between;

          }
          .barcode-text{
              font-family: math;
              font-weight: 600;
              text-align:center;
              width:100%;

          }
          .opex-sku-title-two{
                 font-family: math;
                 width: 100%;
                 text-align: center;
                 font-size:12px;
                 font-weight:bold;
          }
          </style><div class="main-div">';

      foreach($sql as $d)
      {
              $skuNext = $d->opex_sku;

              $sCode = $d->suppliercode;

              if($d->saleorderid == 0)
              {
                $OrdTypeTxt = "PR : ".$d->pr_id;
              }
              else
              {
                $OrdTypeTxt = "M : ".$d->order_number;

                if($d->is_cancelled_production_dropin > 0)
                {
                    //$OrdTypeTxt = "<span style='font-size:10px;font-weight:bold;'>Cancel</span> : ".$d->order_number;

                    $sCode = $sCode." - PR : ".$d->pr_id;
                }


              }
              $topCode = isset($arr[$d->pr_id]) ? $arr[$d->pr_id] : '';

              $typeTextN = $topCode!="" ? explode("-",$topCode)[0]." - ".explode("-",$topCode)[1] : '';

              $accountTExtn =  $topCode!="" ? explode("-",$topCode)[2] : '';

                if($d->is_cancelled_production_dropin > 0)
                {
                    $accountTExtn = "Cancelled";
                }

                if($d->prefix=="M")
                {
                    $typeTextN = "SO";
                }

              $code = $d->barcode;
            //working here for label
          // $barcode = DNS1DFacade::getBarcodePNG($code, 'UPCA', 2, 60);
          //$barcode = DNS1DFacade::getBarcodePNG($code, 'C128', 2, 30);
               // $barcode = DNS1DFacade::getBarcodePNG($code, 'C128',2,60);
        //  $barcode = DNS1DFacade::getBarcodePNG($code, 'C39',2,35);
        $barcode = DNS1DFacade::getBarcodePNG($code, 'C39',2,35);
            //$barcode = DNS1DFacade::getBarcodePNG($code, 'C128A',2,45);


            //valid
           // $barcode = DNS1DFacade::getBarcodePNG($code, 'C39',2,40);

        //   $barcode = DNS1DFacade::getBarcodePNG($code, 'C39',2,35); behtereen settings

              $html.='<div class="single-label-body">';

              $html.='<div class="label-header">


                   <div class="dcodes">
                    <div class="opex-sku" style="font-size: 25px;">'.$typeTextN.'</div>
                    <div class="prefix" style="font-size: 25px;">'.$accountTExtn.'</div>
                </div>

                <div class="barcode" style="margin-bottom:5px;">
                    <center>	<img src="data:image/png;base64,' .$barcode. '" style="" >
                    	<div class="barcode-text">'.$code.'</div></center>
                </div>


                <div class="dcodes">
                    <div class="opex-sku-title">'.$d->title.'</div>
                </div>

                <div class="dcodes">
                    <div class="opex-sku">'.$skuNext.'</div>
                    <div class="prefix">'.$OrdTypeTxt.'</div>
                </div>

                <div class="dcodes">
                    <div class="opex-sku">'.$sCode.'</div>
                    <div class="prefix">'.date('dmy',strtotime($d->created_at)).'-'.date('dmy',strtotime($d->expiry_date)).'</div>
                </div>


            </div>';

            $html.='</div>';




      }

      echo $html;

      echo "<script>window.print();</script>";

        }else{
            echo "<h1>Sorry Something Went Wrong, The Barcode Link has been expired!</h1>";
        }



    }


























}

?>
