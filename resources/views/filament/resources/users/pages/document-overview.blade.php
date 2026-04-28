<x-filament-panels::page>
    @php
        $summary = $this->summary();
        $statusClass = fn (string $status): string => str_replace('_', '-', $status);
    @endphp

    <style>
        .docs-overview { display: grid; gap: 18px; }
        .docs-summary { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 10px; }
        .docs-summary-card { border: 1px solid rgba(148, 163, 184, .28); border-radius: 10px; background: white; padding: 12px 14px; }
        .docs-summary-card span { display: block; color: #64748b; font-size: 12px; font-weight: 700; text-transform: uppercase; }
        .docs-summary-card strong { display: block; margin-top: 4px; color: #0f172a; font-size: 24px; line-height: 1; }
        .docs-group { display: grid; gap: 14px; }
        .docs-group-title { margin: 0; color: #0f172a; font-size: 20px; font-weight: 750; }
        .docs-owner { border: 1px solid rgba(148, 163, 184, .28); border-radius: 12px; background: white; overflow: hidden; }
        .docs-owner-head { display: flex; justify-content: space-between; gap: 12px; align-items: center; padding: 14px 16px; background: #f8fafc; border-bottom: 1px solid rgba(148, 163, 184, .2); }
        .docs-owner-head h3 { margin: 0; color: #111827; font-size: 16px; font-weight: 750; }
        .docs-owner-head p { margin: 3px 0 0; color: #64748b; font-size: 13px; }
        .docs-owner-count { color: #64748b; font-size: 13px; font-weight: 700; }
        .docs-table { width: 100%; border-collapse: collapse; }
        .docs-table th { padding: 10px 14px; color: #64748b; font-size: 12px; text-align: left; text-transform: uppercase; background: #fbfcfd; border-bottom: 1px solid rgba(148, 163, 184, .18); }
        .docs-table td { padding: 12px 14px; border-bottom: 1px solid rgba(148, 163, 184, .18); vertical-align: top; font-size: 13px; }
        .docs-row-child td:first-child { padding-left: 34px; }
        .docs-name { color: #0f172a; font-weight: 700; }
        .docs-child-label { display: inline-flex; margin-left: 6px; border-radius: 999px; background: #eef5f4; color: #0f766e; padding: 2px 8px; font-size: 11px; font-weight: 750; }
        .docs-meta { margin-top: 4px; color: #64748b; line-height: 1.4; }
        .docs-note { max-width: 340px; color: #92400e; line-height: 1.4; overflow-wrap: anywhere; }
        .docs-status { display: inline-flex; min-width: 92px; justify-content: center; border-radius: 999px; padding: 5px 10px; font-size: 12px; font-weight: 750; }
        .docs-status.missing { background: #f1f5f9; color: #475569; }
        .docs-status.pending { background: #fef3c7; color: #92400e; }
        .docs-status.approved { background: #dcfce7; color: #166534; }
        .docs-status.rejected, .docs-status.exemption-rejected { background: #fee2e2; color: #991b1b; }
        .docs-status.exemption-approved { background: #dff3e7; color: #166534; }
        .docs-status.exemption-pending { background: #e0f2fe; color: #075985; }
        .docs-actions { display: flex; flex-wrap: wrap; gap: 8px; }
        .docs-action { display: inline-flex; align-items: center; justify-content: center; min-height: 30px; border-radius: 8px; border: 1px solid rgba(15, 118, 110, .22); color: #0f766e; padding: 0 10px; font-size: 12px; font-weight: 750; text-decoration: none; }
        .docs-action:hover { background: #eef5f4; }
        .docs-empty { padding: 18px; color: #64748b; font-size: 14px; }
        .dark .docs-group-title { color: #ffffff; }
        .dark .docs-summary-card,
        .dark .docs-owner { background: #111827; border-color: rgba(148, 163, 184, .22); }
        .dark .docs-summary-card strong,
        .dark .docs-owner-head h3,
        .dark .docs-name { color: #ffffff; }
        .dark .docs-owner-head,
        .dark .docs-table th { background: #0f172a; }
        .dark .docs-table td,
        .dark .docs-table th,
        .dark .docs-owner-head { border-color: rgba(148, 163, 184, .18); }
        @media (max-width: 1100px) {
            .docs-summary { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .docs-table { min-width: 880px; }
            .docs-owner { overflow-x: auto; }
        }
    </style>

    <div class="docs-overview">
        <div class="docs-summary">
            <div class="docs-summary-card"><span>Totali</span><strong>{{ $summary['total'] }}</strong></div>
            <div class="docs-summary-card"><span>Mancanti</span><strong>{{ $summary['missing'] }}</strong></div>
            <div class="docs-summary-card"><span>In attesa</span><strong>{{ $summary['pending'] }}</strong></div>
            <div class="docs-summary-card"><span>Approvati</span><strong>{{ $summary['approved'] }}</strong></div>
            <div class="docs-summary-card"><span>Respinti</span><strong>{{ $summary['rejected'] }}</strong></div>
            <div class="docs-summary-card"><span>Esenzioni</span><strong>{{ $summary['exemptions'] }}</strong></div>
        </div>

        @foreach ($this->groups() as $group)
            <section class="docs-group">
                <h2 class="docs-group-title">{{ $group['title'] }}</h2>

                @forelse ($group['owners'] as $owner)
                    <article class="docs-owner">
                        <div class="docs-owner-head">
                            <div>
                                <h3>{{ $owner['label'] }}</h3>
                                @if ($owner['meta'])
                                    <p>{{ $owner['meta'] }}</p>
                                @endif
                            </div>
                            <div class="docs-owner-count">{{ count($owner['rows']) }} documenti</div>
                        </div>

                        @if ($owner['rows'] === [])
                            <div class="docs-empty">Nessun template configurato per questa sezione.</div>
                        @else
                            <table class="docs-table">
                                <thead>
                                    <tr>
                                        <th>Documento</th>
                                        <th>Stato</th>
                                        <th>Date</th>
                                        <th>Note</th>
                                        <th>Azioni</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($owner['rows'] as $row)
                                        @include('filament.resources.users.pages.partials.document-overview-row', ['row' => $row, 'statusClass' => $statusClass, 'isChild' => false])

                                        @foreach ($row['children'] as $child)
                                            @include('filament.resources.users.pages.partials.document-overview-row', ['row' => $child, 'statusClass' => $statusClass, 'isChild' => true])
                                        @endforeach
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    </article>
                @empty
                    <article class="docs-owner">
                        <div class="docs-empty">Nessun elemento presente.</div>
                    </article>
                @endforelse
            </section>
        @endforeach
    </div>
</x-filament-panels::page>
