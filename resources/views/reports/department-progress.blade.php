<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>تقرير تقدم الإدارات - {{ $cycle->name }}</title>
    <style>
        @font-face {
            font-family: 'Arial';
            font-style: normal;
        }
        body { font-family: Arial, sans-serif; font-size: 11px; color: #1a1a1a; direction: rtl; }
        h1 { color: #1e3a5f; font-size: 18px; text-align: center; margin-bottom: 4px; }
        .subtitle { text-align: center; color: #666; margin-bottom: 20px; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { background-color: #1e3a5f; color: white; padding: 8px 6px; text-align: center; font-size: 10px; }
        td { padding: 7px 6px; text-align: center; border-bottom: 1px solid #e5e7eb; }
        tr:nth-child(even) { background-color: #f8fafc; }
        .approved { color: #16a34a; font-weight: bold; }
        .rejected { color: #dc2626; font-weight: bold; }
        .overdue  { color: #ea580c; font-weight: bold; }
        .completion { font-weight: bold; }
        .footer { margin-top: 20px; text-align: center; color: #999; font-size: 9px; }
    </style>
</head>
<body>
    <h1>منصة قياس — تقرير تقدم الإدارات</h1>
    <div class="subtitle">
        دورة التقييم: {{ $cycle->name }} ({{ $cycle->year }}) |
        تاريخ التقرير: {{ now()->format('Y-m-d') }}
    </div>

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>الإدارة</th>
                <th>الإجمالي</th>
                <th>معتمدة</th>
                <th>قيد المراجعة</th>
                <th>مرفوضة</th>
                <th>نسبة الإنجاز</th>
            </tr>
        </thead>
        <tbody>
            @foreach($departments as $i => $dept)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td style="text-align:right">{{ $dept->name_ar }}</td>
                <td>{{ $dept->total }}</td>
                <td class="approved">{{ $dept->approved }}</td>
                <td>{{ $dept->under_review }}</td>
                <td class="rejected">{{ $dept->rejected }}</td>
                <td class="completion">
                    @if($dept->total > 0)
                        {{ round(($dept->approved / $dept->total) * 100, 1) }}%
                    @else
                        0%
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        تم إنشاء هذا التقرير تلقائياً من منصة قياس | {{ now()->toDateTimeString() }}
    </div>
</body>
</html>
