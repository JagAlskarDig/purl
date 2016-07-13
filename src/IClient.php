<?php
/**
 * This file is part of the Purl package.
 *
 * (c) pengzhile <pengzhile@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Purl;

interface IClient
{
    public function isVerifyCert();

    public function getStreamFlag();

    public function getConnTimeout();
}