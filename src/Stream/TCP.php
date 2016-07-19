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

namespace Purl\Stream;

use Purl\Common\Helper;

class TCP extends Base
{
    protected function newClient()
    {
        $flag = STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT;
        $remote = 'tcp://' . $this->ip . ':' . $this->port;
        $context = stream_context_create();

        $fp = stream_socket_client($remote, $errNo, $errStr, $this->connTimeout / 1000000, $flag, $context);
        Helper::assert(false !== $fp, 'Unable to init stream, code: ' . $errNo);

        return $fp;
    }
}
