<?php

namespace Mreycode\DbMigrator;

enum Actions : string
{
    case RETRY = 'retry';
    case CLASS = 'class';
    case STATS = 'stats';
    case RESTART = 'restart';
    case RESUME = 'resume';
    case INTERACTIVE = 'interactive';
    case PAUSE = 'pause';
}