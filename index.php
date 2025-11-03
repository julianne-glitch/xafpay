<?php
header('Content-Type: application/json');
echo json_encode(['status'=>'ok','service'=>'XafPay Gateway','time'=>gmdate('c')]);
