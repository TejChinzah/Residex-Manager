<?php
require_once '../includes/config.php';
session_destroy();
redirect(SITE_URL . '/user/login.php');
