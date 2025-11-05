<?php
json_out([
  'ok'      => true,
  'service' => 'XafPay API',
  'env'     => app_env(),
  'time'    => gmdate('c'),
]);
