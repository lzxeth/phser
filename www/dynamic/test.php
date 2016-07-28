<?php
echo json_encode(
    array(
        'GET'    => $_GET,
        'POST'   => $_POST,
        'SERVER' => $_SERVER,
    ),
    JSON_UNESCAPED_UNICODE
);