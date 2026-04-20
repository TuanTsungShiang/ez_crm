<?php

namespace App\Services\Auth;

use App\Models\Member;
use App\Models\MemberVerification;

class OtpService
{
    const OTP_EXPIRE_MINS = 5;
    const RESEND_COOLDOWN = 60;

    public function generate(Member $member, string $type): MemberVerification
    {
        MemberVerification::where('member_id', $member->id)
            ->where('type', $type)
            ->whereNull('verified_at')
            ->update(['expires_at' => now()->subSecond()]);

        return MemberVerification::create([
            'member_id'  => $member->id,
            'type'       => $type,
            'token'      => $this->generateCode(),
            'expires_at' => now()->addMinutes(self::OTP_EXPIRE_MINS),
            'created_at' => now(),
        ]);
    }

    public function verify(Member $member, string $type, string $code): ?MemberVerification
    {
        $verification = MemberVerification::where('member_id', $member->id)
            ->where('type', $type)
            ->where('token', $code)
            ->whereNull('verified_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if (! $verification) {
            return null;
        }

        $verification->update(['verified_at' => now()]);

        return $verification;
    }

    public function isThrottled(Member $member, string $type): bool
    {
        $last = MemberVerification::where('member_id', $member->id)
            ->where('type', $type)
            ->latest('id')
            ->first();

        if (! $last) {
            return false;
        }

        return $last->created_at->diffInSeconds(now()) < self::RESEND_COOLDOWN;
    }

    private function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}
