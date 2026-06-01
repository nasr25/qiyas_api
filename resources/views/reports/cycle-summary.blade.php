<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>ملخص دورة التقييم - {{ $cycle->name }}</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; color: #1a1a1a; direction: rtl; }
        h1 { color: #1e3a5f; font-size: 20px; text-align: center; }
        .info-grid { display: flex; gap: 20px; margin: 20px 0; }
        .info-box { border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; flex: 1; }
        .info-box label { color: #6b7280; font-size: 10px; display: block; }
        .info-box span { font-size: 16px; font-weight: bold; color: #1e3a5f; }
        .stat-row { display: flex; gap: 12px; margin: 20px 0; }
        .stat { border-radius: 8px; padding: 16px; flex: 1; text-align: center; }
        .stat.approved { background: #dcfce7; }
        .stat.review { background: #fef9c3; }
        .stat.rejected { background: #fee2e2; }
        .stat.draft { background: #f3f4f6; }
        .stat.overdue { background: #ffedd5; }
        .stat .number { font-size: 28px; font-weight: bold; }
        .stat .label { font-size: 10px; color: #6b7280; }
        .score { text-align: center; margin: 20px 0; font-size: 40px; font-weight: bold; color: #1e3a5f; }
        .footer { margin-top: 30px; text-align: center; color: #999; font-size: 9px; border-top: 1px solid #e5e7eb; padding-top: 10px; }
    </style>
</head>
<body>
    <h1>منصة قياس — ملخص دورة التقييم</h1>
    <p style="text-align:center;color:#666">{{ $cycle->name }} | {{ $cycle->year }}</p>

    <div class="info-grid">
        <div class="info-box">
            <label>تاريخ البدء</label>
            <span>{{ $cycle->start_date?->format('Y-m-d') }}</span>
        </div>
        <div class="info-box">
            <label>تاريخ الانتهاء</label>
            <span>{{ $cycle->end_date?->format('Y-m-d') }}</span>
        </div>
        <div class="info-box">
            <label>الحالة</label>
            <span>{{ $cycle->status }}</span>
        </div>
        <div class="info-box">
            <label>عدد المعايير</label>
            <span>{{ $cycle->standards->count() }}</span>
        </div>
    </div>

    @if($cycle->final_score)
    <div class="score">الدرجة النهائية: {{ $cycle->final_score }}%</div>
    @endif

    <div class="stat-row">
        <div class="stat approved">
            <div class="number">{{ $docStats['approved'] ?? 0 }}</div>
            <div class="label">معتمدة</div>
        </div>
        <div class="stat review">
            <div class="number">{{ $docStats['under_review'] ?? 0 }}</div>
            <div class="label">قيد المراجعة</div>
        </div>
        <div class="stat rejected">
            <div class="number">{{ $docStats['rejected'] ?? 0 }}</div>
            <div class="label">مرفوضة</div>
        </div>
        <div class="stat draft">
            <div class="number">{{ $docStats['draft'] ?? 0 }}</div>
            <div class="label">مسودة</div>
        </div>
        <div class="stat overdue">
            <div class="number">{{ $docStats['overdue'] ?? 0 }}</div>
            <div class="label">متأخرة</div>
        </div>
    </div>

    @if($cycle->closing_notes)
    <div style="margin-top:20px;padding:16px;background:#f8fafc;border-radius:8px;">
        <strong>ملاحظات الإغلاق:</strong>
        <p>{{ $cycle->closing_notes }}</p>
    </div>
    @endif

    <div class="footer">
        تم إنشاء هذا التقرير تلقائياً من منصة قياس | {{ now()->toDateTimeString() }}
    </div>
</body>
</html>
