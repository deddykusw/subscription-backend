<?php

namespace App\Support;

use Illuminate\Http\Request;

final class RequestAttendanceToken
{
    /**
     * Contract: GET uses query `?attendance_token=`; POST JSON and multipart use field `attendance_token`.
     * If both are present, query string wins (same value expected in practice).
     */
    public static function from(Request $request): ?string
    {
        $query = $request->query('attendance_token');
        if (is_string($query) && $query !== '') {
            return $query;
        }

        $input = $request->input('attendance_token');
        if (is_string($input) && $input !== '') {
            return $input;
        }

        return null;
    }
}
