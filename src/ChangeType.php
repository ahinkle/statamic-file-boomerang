<?php

namespace Ahinkle\FileBoomerang;

enum ChangeType: string
{
    case Put = 'put';
    case Delete = 'delete';
}
