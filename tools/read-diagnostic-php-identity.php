<?php
// Inventory only: no autoload, application bootstrap, configuration dump or network.
$extensions = get_loaded_extensions();
sort($extensions);
echo json_encode(['version'=>PHP_VERSION,'bits'=>PHP_INT_SIZE*8,'binary'=>PHP_BINARY,'ini_loaded'=>php_ini_loaded_file() ?: null,'extensions'=>$extensions],JSON_THROW_ON_ERROR).PHP_EOL;
