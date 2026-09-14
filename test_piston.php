<?php
$ch = curl_init('https://wandbox.org/api/compile.json');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'compiler' => 'cpython-3.10.6',
    'code' => 'x = int(input())\ny = int(input())\nprint(x+y)',
    'stdin' => "10\n20\n"
]));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
echo $response;
