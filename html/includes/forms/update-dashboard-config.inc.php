<?php

use LibreNMS\Authentication\Auth;

header('Content-type: application/json');

$status  = 'error';
$message = 'Error updating user dashboard config';

$data = json_decode($_POST['data'], true);
$sub_type = mres($_POST['sub_type']);
$widget_id = mres($_POST['widget_id']);
$dasboard_id = mres($_POST['dashboard_id']);

if ($sub_type == 'remove' && is_numeric($widget_id)) {
    if (dbFetchCell('select 1 from dashboards where (user_id = ? || access = 2) && dashboard_id = ?', array(Auth::id(),$dasboard_id)) == 1) {
        if ($widget_id == 0 || dbDelete('users_widgets', '`user_widget_id`=? AND `dashboard_id`=?', array($widget_id,$dasboard_id))) {
            $status = 'ok';
            $message = '';
        }
    } else {
        $status = 'error';
        $message = 'ERROR: You have no write access.';
    }
} elseif ($sub_type == 'remove-all') {
    if (dbFetchCell('select 1 from dashboards where (user_id = ? || access = 2) && dashboard_id = ?', array(Auth::id(),$dasboard_id)) == 1) {
        if (dbDelete('users_widgets', '`dashboard_id`=?', array($dasboard_id))) {
            $status = 'ok';
            $message = '';
        }
    } else {
        $status = 'error';
        $message = 'ERROR: You have no write access.';
    }
} elseif ($sub_type == 'add' && is_numeric($widget_id)) {
    if (dbFetchCell('select 1 from dashboards where (user_id = ? || access = 2) && dashboard_id = ?', array(Auth::id(),$dasboard_id)) == 1) {
        $widget = dbFetchRow('SELECT * FROM `widgets` WHERE `widget_id`=?', array($widget_id));
        if (is_array($widget)) {
            list($x,$y) = explode(',', $widget['base_dimensions']);
            $item_id = dbInsert(array('user_id'=>Auth::id(),'widget_id'=>$widget_id, 'col'=>1,'row'=>1,'refresh'=>60,'title'=>$widget['widget_title'],'size_x'=>$x,'size_y'=>$y,'settings'=>'','dashboard_id'=>$dasboard_id), 'users_widgets');
            if (is_numeric($item_id)) {
                $extra = array('user_widget_id'=>$item_id,'widget_id'=>$item_id,'title'=>$widget['widget_title'],'widget'=>$widget['widget'],'refresh'=>60,'size_x'=>$x,'size_y'=>$y);
                $status = 'ok';
                $message = '';
            }
        }
    } else {
        $status = 'error';
        $message = 'ERROR: You have no write access.';
    }
} else {
    if (dbFetchCell('select 1 from dashboards where (user_id = ? || access = 2) && dashboard_id = ?', array(Auth::id(),$dasboard_id)) == 1) {
        $status = 'ok';
        $message = '';
        foreach ($data as $line) {
            if (is_array($line)) {
                $update = array('col'=>$line['col'],'row'=>$line['row'],'size_x'=>$line['size_x'],'size_y'=>$line['size_y']);
                dbUpdate($update, 'users_widgets', '`user_widget_id`=? AND `dashboard_id`=?', array($line['id'],$dasboard_id));
            }
        }
    } else {
        $status = 'error';
        $message = 'ERROR: You have no write access.';
    }
}

$response = array(
    'status'        => $status,
    'message'       => $message,
    'extra'         => $extra,
);
echo _json_encode($response);
