#!/usr/bin/env php
<?php
Phar::mapPhar('local-migrator.phar');
require 'phar://local-migrator.phar/bin/lm';
__HALT_COMPILER();
