<?php

// Unit tests must load extension classes from src, never the staged site copy.
$extensionLoader = require "vendor/autoload.php";
require "site/vendor/autoload.php";
$extensionLoader->unregister();
$extensionLoader->register(true);
