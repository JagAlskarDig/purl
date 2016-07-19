<?php
/**
 * This file is part of the Purl package.
 * Copyright (C) 2016 pengzhile <pengzhile@gmail.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */

namespace Purl\Common;

use Exception;

class Helper
{
    /**
     * @var array
     */
    protected static $dnsCache = array();

    /**
     * @param bool $cond
     * @param string $message
     * @throws Exception
     */
    public static function assert($cond, $message)
    {
        if ($cond) {
            return;
        }

        throw new Exception($message);
    }

    /**
     * @param string $host
     * @return bool|string
     */
    public static function host2ip($host)
    {
        $host = strtolower($host);
        if (isset(self::$dnsCache[$host])) {
            return self::$dnsCache[$host];
        }

        $ip = gethostbyname($host);
        if (false === ip2long($ip)) {
            return false;
        }

        self::$dnsCache[$host] = $ip;

        return $ip;
    }
}
