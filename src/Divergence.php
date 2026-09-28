<?php

namespace Ahinkle\FileBoomerang;

enum Divergence
{
    case Skip;
    case PreferEditor;
    case Merge;
}
