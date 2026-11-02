<?php

namespace facilo\portero\Core\Ports;

interface HttpClient
{
    /** @return array{status:int, body:string} */
    public function get(string $url): array;

    /**
     * @param array<string,string> $fields
     * @return array{status:int, body:string}
     */
    public function postForm(string $url, array $fields): array;
}
