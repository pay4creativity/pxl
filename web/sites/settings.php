<?php
$databases['default']['default'] = array (
  'database' => 'pixelerant_db',
  'username' => 'pixelerant',
  'password' => 'p1xElEranT',
  'prefix' => '',
  'host' => 'db',
  'port' => '3306',
  'isolation_level' => 'READ COMMITTED',
  'driver' => 'mysql',
  'namespace' => 'Drupal\\mysql\\Driver\\Database\\mysql',
  'autoload' => 'core/modules/mysql/src/Driver/Database/mysql/',
);
$settings['hash_salt'] = 'z0bR1gwAT6osvTz7aVz9rBbeaNfCWBVWMFdqyRVbcfNXtiRVcRL7OkjeFR_DQSSUd_t7sjveuA';

$settings['trusted_host_patterns'] = [
'^pixelerant.com$',
'^ip-172-31-10-188$',
'^65\.0\.19\.88$',
'^127\.0\.0\.1$',
];
$settings['config_sync_directory'] = 'config';
