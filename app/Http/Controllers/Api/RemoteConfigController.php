<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RemoteConfigController extends ApiController
{
    /**
     * GET /api/v1/remote-config
     *
     * Public remote config for mobile apps.
     */
    public function show(Request $request): JsonResponse
    {
        $presensiBaseUrl = config('remote_config.presensi.base_url')
            ?? rtrim((string) config('services.attendance.url'), '/') . '/api';

        $subscriptionBaseUrl = config('remote_config.subscription.base_url')
            ?? rtrim((string) config('app.url'), '/') . '/api/v1';

        return $this->success([
            'version'  =>  config('remote_config.version', 1),
            'presensi' => [
                'base_url'         => $presensiBaseUrl,
                'timeout_seconds'  => (int) config('remote_config.presensi.timeout_seconds', 30),
                'endpoints'        => [
                    '/absen'          => '/absen',
                    '/absen/summary'  => '/absen/summary',
                    '/absen/sesi-aja' => '/absen/sesi-aja',
                    '/user/profile'   => '/user/profile',
                ],
                'params'           => [
                    'page'     => 'page',
                    'per_page' => 'per_page',
                ],
                'fields'           => [
                    'absen_lat'     => 'absen_lat',
                    'absen_long'    => 'absen_long',
                    'absen_foto'    => 'absen_foto',
                    'footprint'    => 'footprint',
                    'version_name' => 'version_name',
                    'is_luar'      => 'is_luar',
                    'keterangan'   => 'keterangan',
                ],
            ],
            'subscription' => [
                'base_url'        => $subscriptionBaseUrl,
                'timeout_seconds' => (int) config('remote_config.subscription.timeout_seconds', 30),
                'endpoints'       => [
                    '/auth/exchange-token'   => '/auth/exchange-token',
                    '/auth/profile-by-token' => '/auth/profile-by-token',
                    '/voucher/history'       => '/voucher/history',
                    '/voucher/redeem'        => '/voucher/redeem',
                    '/messages'              => '/messages',
                ],
                'params'          => [
                    'attendance_token' => 'attendance_token',
                    'page'             => 'page',
                    'per_page'         => 'per_page',
                    'device_name'      => 'device_name',
                    'username'         => 'username',
                    'password'         => 'password',
                    'code'             => 'code',
                    'message'          => 'message',
                ],
                'fields'          => (object) [],
            ],
        ]);
    }
}

