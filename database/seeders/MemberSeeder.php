<?php

namespace Database\Seeders;

use App\Models\Member;
use App\Models\MemberGroup;
use App\Models\MemberProfile;
use App\Models\MemberSns;
use App\Models\Tag;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class MemberSeeder extends Seeder
{
    public function run(): void
    {
        // 建立分群
        $groups = collect([
            ['name' => '一般會員', 'sort_order' => 1],
            ['name' => '銀牌會員', 'sort_order' => 2],
            ['name' => '金牌會員', 'sort_order' => 3],
            ['name' => 'VIP',     'sort_order' => 4],
        ])->map(fn($g) => MemberGroup::create($g));

        // 建立標籤
        $tags = collect([
            ['name' => '潛力客',   'color' => '#3B82F6'],
            ['name' => 'VIP',     'color' => '#F59E0B'],
            ['name' => '流失風險', 'color' => '#EF4444'],
            ['name' => '活躍用戶', 'color' => '#10B981'],
        ])->map(fn($t) => Tag::create($t));

        // 建立會員測試資料
        $members = [
            ['name' => '王小明', 'nickname' => 'Ming',   'email' => 'ming@example.com',   'phone' => '0912345001', 'status' => 1, 'gender' => 1, 'has_sns' => true],
            ['name' => '林美華', 'nickname' => 'Hua',    'email' => 'hua@example.com',    'phone' => '0912345002', 'status' => 1, 'gender' => 2, 'has_sns' => true],
            ['name' => '陳大偉', 'nickname' => 'David',  'email' => 'david@example.com',  'phone' => '0912345003', 'status' => 0, 'gender' => 1, 'has_sns' => false],
            ['name' => '張雅婷', 'nickname' => 'Ting',   'email' => 'ting@example.com',   'phone' => '0912345004', 'status' => 1, 'gender' => 2, 'has_sns' => false],
            ['name' => '李志豪', 'nickname' => 'Hao',    'email' => 'hao@example.com',    'phone' => '0912345005', 'status' => 2, 'gender' => 1, 'has_sns' => true],
            ['name' => '吳淑芬', 'nickname' => 'Fen',    'email' => 'fen@example.com',    'phone' => '0912345006', 'status' => 1, 'gender' => 2, 'has_sns' => false],
            ['name' => '黃建國', 'nickname' => 'Jian',   'email' => 'jian@example.com',   'phone' => '0912345007', 'status' => 1, 'gender' => 1, 'has_sns' => true],
            ['name' => '劉怡君', 'nickname' => 'June',   'email' => 'june@example.com',   'phone' => '0912345008', 'status' => 1, 'gender' => 2, 'has_sns' => false],
            ['name' => '蔡宗翰', 'nickname' => 'Han',    'email' => 'han@example.com',    'phone' => '0912345009', 'status' => 0, 'gender' => 1, 'has_sns' => false],
            ['name' => '鄭雅文', 'nickname' => 'Wendy',  'email' => 'wendy@example.com',  'phone' => '0912345010', 'status' => 1, 'gender' => 2, 'has_sns' => true],
        ];

        foreach ($members as $i => $data) {
            $member = Member::create([
                'uuid'            => Str::uuid(),
                'member_group_id' => $groups[$i % 4]->id,
                'name'            => $data['name'],
                'nickname'        => $data['nickname'],
                'email'           => $data['email'],
                'phone'           => $data['phone'],
                'password'        => bcrypt('password'),
                'status'          => $data['status'],
                'last_login_at'   => now()->subDays(rand(0, 30)),
            ]);

            MemberProfile::create([
                'member_id' => $member->id,
                'gender'    => $data['gender'],
                'birthday'  => now()->subYears(rand(20, 45))->format('Y-m-d'),
                'language'  => 'zh-TW',
                'timezone'  => 'Asia/Taipei',
            ]);

            if ($data['has_sns']) {
                MemberSns::create([
                    'member_id'        => $member->id,
                    'provider'         => collect(['google', 'line', 'facebook'])->random(),
                    'provider_user_id' => Str::random(20),
                ]);
            }

            // 每位會員隨機掛 1~2 個標籤
            $member->tags()->attach(
                $tags->random(rand(1, 2))->pluck('id')->toArray()
            );
        }
    }
}
