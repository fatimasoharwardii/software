<?php
require_once "session.php";

function checkLogin(){
    if(!isset($_SESSION['user_id'])){
        header("Location: /garment-erp/login.php");
        exit();
    }
}

function checkAdmin(){
    checkLogin();
    if($_SESSION['role'] != 'admin'){
        header("Location: /garment-erp/user/dashboard.php");
        exit();
    }
}

function checkUser(){
    checkLogin();
}
?>