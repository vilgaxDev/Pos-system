<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Transaction, App\NotificationTemplate;
use App\Restaurant\Booking;

use \Notification;

use App\Notifications\CustomerNotification;
use App\Notifications\SupplierNotification;

use App\Utils\Util;

class NotificationController extends Controller
{
    protected $commonUtil;

    /**
     * Constructor
     *
     * @param Util $commonUtil
     * @return void
     */
    public function __construct(Util $commonUtil)
    {
        $this->commonUtil = $commonUtil;
    }

    /**
     * Display a notification view.
     *
     * @return \Illuminate\Http\Response
     */
    public function getTemplate($transaction_id, $template_for)
    {
    	if (!auth()->user()->can('send_notification') ) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');

    	$notification_template = NotificationTemplate::getTemplate($business_id, $template_for);

        $tags = NotificationTemplate::notificationTags();

        if($template_for == 'new_booking'){
            $transaction = Booking::where('business_id', $business_id)
                            ->with(['customer'])
                            ->find($transaction_id);

            $transaction->contact = $transaction->customer;
            $tags = NotificationTemplate::bookingNotificationTags();
        } else{
            $transaction = Transaction::where('business_id', $business_id)
                            ->with(['contact'])
                            ->find($transaction_id);
        }

        $customer_notifications = NotificationTemplate::customerNotifications();
        $supplier_notifications = NotificationTemplate::supplierNotifications();

        $template_name = '';
        if(array_key_exists($template_for, $customer_notifications)){
            $template_name = $customer_notifications[$template_for]['name'];
        } elseif (array_key_exists($template_for, $supplier_notifications)) {
            $template_name = $supplier_notifications[$template_for]['name'];
        }

    	return view('notification.show_template')
                ->with(compact('notification_template', 'transaction', 'tags', 'template_name'));
    }

    /**
     * Sends notifications to customer and supplier
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function send(Request $request)
    {
        if (!auth()->user()->can('send_notification') ) {
            abort(403, 'Unauthorized action.');
        }
        $notAllowed = $this->commonUtil->notAllowedInDemo();
        if(!empty($notAllowed)){
            return $notAllowed;
        }

        try {

            $customer_notifications = NotificationTemplate::customerNotifications();
            $supplier_notifications = NotificationTemplate::supplierNotifications();

            $data = $request->only(['to_email', 'subject', 'email_body', 'mobile_number', 'sms_body', 'notification_type']);

            $transaction_id = $request->input('transaction_id');

            if($request->input('template_for') == 'new_booking'){
                $data['email_body'] = $this->replaceBookingTags($data['email_body'], $transaction_id);

                $data['sms_body'] = $this->replaceBookingTags($data['sms_body'], $transaction_id);

                $data['subject'] = $this->replaceBookingTags($data['subject'], $transaction_id);
            } else {
                $data['email_body'] = $this->replaceTags($data['email_body'], $transaction_id);

                $data['sms_body'] = $this->replaceTags($data['sms_body'], $transaction_id);

                $data['subject'] = $this->replaceTags($data['subject'], $transaction_id);
            }
            

            $data['email_settings'] = request()->session()->get('business.email_settings');

            $data['sms_settings'] = request()->session()->get('business.sms_settings');

            $notification_type = $request->input('notification_type');

            if (array_key_exists($request->input('template_for'), $customer_notifications)){
                
                if($notification_type == 'email_only'){
                    Notification::route('mail', $data['to_email'])
                                    ->notify(new CustomerNotification($data));
                } elseif ($notification_type == 'sms_only') {
                    $this->commonUtil->sendSms($data);
                } elseif ($notification_type == 'both') {
                    Notification::route('mail', $data['to_email'])
                                ->notify(new CustomerNotification($data));

                    $this->commonUtil->sendSms($data);
                }

            } elseif (array_key_exists($request->input('template_for'), $supplier_notifications)) 
            {
                if($notification_type == 'email_only'){
                    Notification::route('mail', $data['to_email'])
                                    ->notify(new SupplierNotification($data));
                } elseif ($notification_type == 'sms_only') {
                    $this->commonUtil->sendSms($data);
                } elseif ($notification_type == 'both') {
                    Notification::route('mail', $data['to_email'])
                                ->notify(new SupplierNotification($data));

                    $this->commonUtil->sendSms($data);
                }
            }

            $output = ['success' => 1, 'msg' => __('lang_v1.notification_sent_successfully')];
        }  catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());
            
            $output = ['success' => 0,
                            'msg' => __('messages.something_went_wrong')
                        ];
        }

        return $output;
    }

    /**
     * Replaces tags from notification body with original value
     *
     * @param  text  $body
     * @param  int  $transaction_id
     *
     * @return array
     */
    private function replaceTags($body, $transaction_id){

        $business_id = request()->session()->get('user.business_id');
        $transaction = Transaction::where('business_id', $business_id)
                            ->with(['contact', 'payment_lines'])
                            ->findOrFail($transaction_id);

        //Replace contact name
        if (strpos($body, '{contact_name}') !== false) {
            $contact_name = $transaction->contact->name;

            $body = str_replace('{contact_name}', $contact_name, $body);
        }

        //Replace invoice number
        if (strpos($body, '{invoice_number}') !== false) {
            $invoice_number = $transaction->type == 'sell' ? $transaction->invoice_no : $transaction->ref_no;

            $body = str_replace('{invoice_number}', $invoice_number, $body);
        }

        //Replace total_amount
        if (strpos($body, '{total_amount}') !== false) {
            $total_amount = $this->commonUtil->num_f($transaction->final_total, true);

            $body = str_replace('{total_amount}', $total_amount, $body);
        }

        $total_paid = 0;
        foreach ($transaction->payment_lines as $payment) {
            if($payment->is_return != 1){
                $total_paid += $payment->amount;
            }
        }
        //Replace total_amount
        if (strpos($body, '{paid_amount}') !== false) {
            $paid_amount = $this->commonUtil->num_f($total_paid, true);

            $body = str_replace('{paid_amount}', $paid_amount, $body);
        }

        //Replace due_amount
        if (strpos($body, '{due_amount}') !== false) {
            $due = $transaction->final_total - $total_paid;
            $due_amount = $this->commonUtil->num_f($due, true);

            $body = str_replace('{due_amount}', $due_amount, $body);
        }

        //Replace business_name
        if (strpos($body, '{business_name}') !== false) {
            $business_name = request()->session()->get('business.name');
            $body = str_replace('{business_name}', $business_name, $body);
        }

        //Replace business_logo
        if (strpos($body, '{business_logo}') !== false) {
            $logo_name = request()->session()->get('business.logo');
            $business_logo = !empty($logo_name) ? '<img src="' . url( 'storage/business_logos/' . $logo_name ) . '" alt="Business Logo" >' : '';

            $body = str_replace('{business_logo}', $business_logo, $body);
        }
        return $body;

    }

    /**
     * Replaces tags from notification body with original value
     *
     * @param  text  $body
     * @param  int  $booking_id
     *
     * @return array
     */
    private function replaceBookingTags($body, $booking_id){

        $business_id = request()->session()->get('user.business_id');
        $booking = Booking::where('business_id', $business_id)
                            ->with(['customer', 'table', 'correspondent', 'waiter', 'location', 'business'])
                            ->findOrFail($booking_id);

         //Replace contact name
        if (strpos($body, '{contact_name}') !== false) {
            $contact_name = $booking->customer->name;

            $body = str_replace('{contact_name}', $contact_name, $body);
        }

        //Replace table
        if (strpos($body, '{table}') !== false) {
            $table = !empty($booking->table->name) ?  $booking->table->name : '';

            $body = str_replace('{table}', $table, $body);
        }

        //Replace start_time
        if (strpos($body, '{start_time}') !== false) {
            $start_time = $this->commonUtil->format_date($booking->booking_start, true);

            $body = str_replace('{start_time}', $start_time, $body);
        }

        //Replace end_time
        if (strpos($body, '{end_time}') !== false) {
            $end_time = $this->commonUtil->format_date($booking->booking_end, true);

            $body = str_replace('{end_time}', $end_time, $body);
        }
        //Replace location
        if (strpos($body, '{location}') !== false) {
            $location = $booking->location->name;

            $body = str_replace('{location}', $location, $body);
        }

        //Replace service_staff
        if (strpos($body, '{service_staff}') !== false) {
            $service_staff = !empty($booking->waiter) ? $booking->waiter->user_full_name : '';

            $body = str_replace('{service_staff}', $service_staff, $body);
        }

        //Replace service_staff
        if (strpos($body, '{correspondent}') !== false) {
            $correspondent = !empty($booking->correspondent) ? $booking->correspondent->user_full_name : '';

            $body = str_replace('{correspondent}', $correspondent, $body);
        }

        //Replace business_name
        if (strpos($body, '{business_name}') !== false) {
            $business_name = request()->session()->get('business.name');
            $body = str_replace('{business_name}', $business_name, $body);
        }

        //Replace business_logo
        if (strpos($body, '{business_logo}') !== false) {
            $logo_name = request()->session()->get('business.logo');
            $business_logo = !empty($logo_name) ? '<img src="' . url( 'storage/business_logos/' . $logo_name ) . '" alt="Business Logo" >' : '';

            $body = str_replace('{business_logo}', $business_logo, $body);
        }
        return $body;
    }
}
