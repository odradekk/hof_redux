<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('game:maintain')->everyMinute()->withoutOverlapping();
