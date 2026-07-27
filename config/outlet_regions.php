<?php

$path = resource_path('data/outlet-regions.json');

return is_file($path)
    ? json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)
    : [];
