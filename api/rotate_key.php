<?php
require_once __DIR__ . '/../../config.php';
header('Content-Type: application/json');
$pdo = db_connect();

$input = json_decode(file_get_contents('php://input'), true);
$id   = $input['merchant_id'] ?? '';
$type = $input['type'] ?? 'secret';

if (!$id) json_out(['ok'=>false,'error'=>'merchant_id required'],400);

$newKey = ($type === 'secret')
    ? bin2hex(random_bytes(16))
    : 'xaf_' . bin2hex(random_bytes(8));

$field = ($type === 'secret') ? 'secret_key' : 'api_key';

$stmt = $pdo->prepare("UPDATE merchants SET {$field} = :k WHERE id = :id RETURNING merchant_name");
$stmt->execute(['k'=>$newKey, 'id'=>$id]);
$merchant = $stmt->fetchColumn();

if (!$merchant) json_out(['ok'=>false,'error'=>'merchant not found'],404);

$pdo->prepare("INSERT INTO admin_actions (admin_user, action, target, details) VALUES ('joel','rotate_key',:t,:d)")
    ->execute(['t'=>$merchant,'d'=>$field]);

json_out(['ok'=>true,'message'=>"$field rotated for $merchant",'new_key'=>$newKey]);
