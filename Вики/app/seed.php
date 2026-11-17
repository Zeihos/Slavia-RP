<?php
function seed_categories():array {$d=json_decode((string)file_get_contents(__DIR__.'/seed-data.json'),true);return $d['categories']??[];}
function seed_articles():array {$d=json_decode((string)file_get_contents(__DIR__.'/seed-data.json'),true);return $d['articles']??[];}
