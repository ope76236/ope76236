<?php
echo "SAPI: " . PHP_SAPI . "\n";
echo "PHP: " . PHP_VERSION . "\n";
echo "BIN: " . (defined('PHP_BINARY') ? PHP_BINARY : 'n/a') . "\n";
echo "CWD: " . getcwd() . "\n";