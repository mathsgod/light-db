<?php

declare(strict_types=1);
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

use PHPUnit\Framework\TestCase;

final class QueryTest extends TestCase
{
    public function testFilters()
    {
        $q = User::Query()->filters([
            "user_id" => [
                "eq" => 1
            ]
        ]);

        $user = $q->first();
        $this->assertEquals(1, $user->user_id);
    }
}
