<x-filament-panels::page>
    @php
        $summary = $this->summary();
        $matrix = $this->matrix();
        $statusClass = fn (string $status): string => str_replace('_', '-', $status);
        $cards = [
            ['filter' => 'all', 'label' => 'Totali', 'value' => $summary['total'], 'tone' => 'all'],
            ['filter' => 'missing', 'label' => 'Mancanti', 'value' => $summary['missing'], 'tone' => 'missing'],
            ['filter' => 'pending', 'label' => 'In attesa', 'value' => $summary['pending'], 'tone' => 'pending'],
            ['filter' => 'approved', 'label' => 'Approvati', 'value' => $summary['approved'], 'tone' => 'approved'],
            ['filter' => 'rejected', 'label' => 'Respinti', 'value' => $summary['rejected'], 'tone' => 'rejected'],
            ['filter' => 'expired', 'label' => 'Scaduti', 'value' => $summary['expired'], 'tone' => 'expired'],
            ['filter' => 'expiring', 'label' => 'In scadenza', 'value' => $summary['expiring'], 'tone' => 'expiring'],
            ['filter' => 'exemptions', 'label' => 'Esenzioni', 'value' => $summary['exemptions'], 'tone' => 'exemptions'],
        ];
    @endphp

    <style>
        .company-overview { display: grid; gap: 18px; }
        .company-summary { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; }
        .company-toolbar { display: flex; align-items: center; gap: 12px; }
        .company-search { width: min(360px, 100%); }
        .company-search input { width: 100%; border: 1px solid rgba(148, 163, 184, .28); border-radius: 10px; background: white; color: #0f172a; padding: 11px 14px; font-size: 14px; outline: none; transition: border-color .16s ease, box-shadow .16s ease; }
        .company-search input:focus { border-color: rgba(15, 118, 110, .55); box-shadow: 0 0 0 3px rgba(15, 118, 110, .12); }
        .company-summary-card { display: block; border: 1px solid rgba(148, 163, 184, .28); border-radius: 10px; background: white; padding: 12px 14px; text-decoration: none; transition: border-color .16s ease, background .16s ease, transform .16s ease; }
        .company-summary-card:hover { border-color: rgba(15, 118, 110, .38); background: #f8fafc; transform: translateY(-1px); }
        .company-summary-card.is-active { border-color: rgba(15, 118, 110, .65); background: #eef5f4; }
        .company-summary-card span { display: block; color: #64748b; font-size: 12px; font-weight: 700; text-transform: uppercase; }
        .company-summary-card strong { display: block; margin-top: 4px; color: #0f172a; font-size: 24px; line-height: 1; }
        .company-summary-card.missing strong,
        .company-summary-card.rejected strong,
        .company-summary-card.expired strong { color: #991b1b; }
        .company-summary-card.pending strong,
        .company-summary-card.expiring strong { color: #92400e; }
        .company-summary-card.approved strong,
        .company-summary-card.exemptions strong { color: #166534; }
        .company-panel { border: 1px solid rgba(148, 163, 184, .28); border-radius: 12px; background: white; overflow: hidden; }
        .company-panel-head { display: flex; justify-content: space-between; gap: 10px; align-items: center; padding: 9px 11px; background: #f8fafc; border-bottom: 1px solid rgba(148, 163, 184, .2); }
        .company-panel-head h3 { margin: 0; color: #111827; font-size: 15px; font-weight: 750; }
        .company-panel-head p { margin: 2px 0 0; color: #64748b; font-size: 11px; }
        .company-panel-count { color: #64748b; font-size: 11px; font-weight: 700; }
        .company-table-wrap { overflow-x: auto; cursor: grab; }
        .company-table-wrap.is-dragging { cursor: grabbing; user-select: none; }
        .company-table { width: max-content; min-width: 100%; border-collapse: collapse; table-layout: auto; }
        .company-table th { position: sticky; top: 0; z-index: 5; padding: 8px 12px 10px; color: #64748b; font-size: 8.5px; line-height: 1.16; text-align: left; text-transform: uppercase; background: #fbfcfd; border-bottom: 1px solid rgba(148, 163, 184, .18); vertical-align: top; overflow-wrap: anywhere; word-break: break-word; }
        .company-table td { padding: 18px 12px; border-bottom: 1px solid rgba(148, 163, 184, .18); vertical-align: top; font-size: 10px; }
        .company-table tbody tr { min-height: 88px; }
        .company-table th:first-child,
        .company-table td:first-child { width: 128px; min-width: 128px; max-width: 128px; position: sticky; left: 0; z-index: 3; background: #ffffff; box-shadow: 8px 0 12px rgba(15, 23, 42, 0.08); }
        .company-table thead th:first-child { z-index: 8; background: #fbfcfd; }
        .company-table th:not(:first-child),
        .company-table td:not(:first-child) { min-width: 108px; }
        .company-company-cell strong { display: block; color: #0f172a; font-size: 11px; font-weight: 750; line-height: 1.2; overflow-wrap: anywhere; }
        .company-company-cell span { display: block; margin-top: 2px; color: #64748b; font-size: 10px; line-height: 1.15; overflow-wrap: anywhere; }
        .company-doc-cell { display: grid; align-content: start; justify-items: start; gap: 3px; min-width: 88px; min-height: 60px; }
        .company-doc-heading { display: inline-block; text-decoration: underline dotted rgba(100, 116, 139, 0.85); text-underline-offset: 3px; text-decoration-thickness: 1px; cursor: help; }
        .company-doc-status { display: inline-flex; width: fit-content; min-width: 76px; justify-content: center; border-radius: 999px; padding: 5px 11px; border: 1px solid transparent; box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.12); font-size: 9px; font-weight: 800; letter-spacing: .01em; line-height: 1.1; margin-top: 4px; }
        .company-doc-status.missing { background: #f3f4f6; border-color: #d1d5db; color: #374151; }
        .company-doc-status.pending { background: #fef3c7; border-color: #f59e0b; color: #92400e; }
        .company-doc-status.approved { background: #dcfce7; border-color: #22c55e; color: #166534; }
        .company-doc-status.rejected { background: #fee2e2; border-color: #ef4444; color: #991b1b; }
        .company-doc-status.expired { background: #7f1d1d; border-color: #fecaca; color: #ffffff; }
        .company-doc-status.expiring { background: #ffedd5; border-color: #f97316; color: #9a3412; }
        .company-doc-status.exemption-approved { background: #d1fae5; border-color: #10b981; color: #065f46; }
        .company-doc-status.exemption-pending { background: #dbeafe; border-color: #3b82f6; color: #1d4ed8; }
        .company-doc-status.exemption-rejected { background: #fce7f3; border-color: #ec4899; color: #9d174d; }
        .company-doc-note { color: #92400e; font-size: 9px; line-height: 1.18; overflow-wrap: anywhere; max-width: 94px; }
        .company-empty { padding: 18px; color: #64748b; font-size: 14px; }
        .dark .company-summary-card,
        .dark .company-panel { background: #111827; border-color: rgba(148, 163, 184, .22); }
        .dark .company-search input { background: #111827; color: #ffffff; border-color: rgba(148, 163, 184, .22); }
        .dark .company-search input:focus { border-color: rgba(45, 212, 191, .45); box-shadow: 0 0 0 3px rgba(45, 212, 191, .12); }
        .dark .company-summary-card:hover,
        .dark .company-summary-card.is-active { background: #0f172a; border-color: rgba(45, 212, 191, .45); }
        .dark .company-summary-card strong,
        .dark .company-panel-head h3,
        .dark .company-company-cell strong { color: #ffffff; }
        .dark .company-panel-head,
        .dark .company-table th,
        .dark .company-table thead th:first-child { background: #0f172a; }
        .dark .company-table td:first-child { background: #111827; box-shadow: 12px 0 18px rgba(2, 6, 23, 0.45); }
        .dark .company-table td,
        .dark .company-table th,
        .dark .company-panel-head { border-color: rgba(148, 163, 184, .18); }
        @media (max-width: 1100px) {
            .company-toolbar { flex-direction: column; align-items: stretch; }
            .company-search { width: 100%; }
            .company-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .company-table th:first-child,
            .company-table td:first-child { width: 112px; min-width: 112px; max-width: 112px; }
            .company-table th:not(:first-child),
            .company-table td:not(:first-child) { min-width: 98px; }
            .company-doc-cell { min-width: 80px; }
        }
    </style>

    <div class="company-overview" wire:poll.15s>
        <div class="company-toolbar">
            <label class="company-search">
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Cerca societa o email"
                >
            </label>
        </div>

        <div class="company-summary">
            @foreach ($cards as $card)
                <a
                    class="company-summary-card {{ $card['tone'] }} {{ $this->filter === $card['filter'] ? 'is-active' : '' }}"
                    href="{{ $this->filterUrl($card['filter']) }}"
                    wire:navigate
                >
                    <span>{{ $card['label'] }}</span>
                    <strong>{{ $card['value'] }}</strong>
                </a>
            @endforeach
        </div>

        <section class="company-panel">
            <div class="company-panel-head">
                <div>
                    <h3>Sezione Societa</h3>
                    <p>Panoramica aggregata di tutte le aziende</p>
                </div>
                <div class="company-panel-count">{{ count($matrix['owners']) }} societa visibili</div>
            </div>

            @if ($matrix['columns'] === [] || $matrix['owners'] === [])
                <div class="company-empty">Nessun documento della sezione societa presente per questo filtro.</div>
            @else
                <div class="company-table-wrap" data-drag-scroll>
                    <table class="company-table">
                        <thead>
                            <tr>
                                <th>Societa</th>
                                @foreach ($matrix['columns'] as $column)
                                    <th><span class="company-doc-heading" title="{{ $column['name'] }}">{{ $column['short_name'] }}</span></th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($matrix['owners'] as $owner)
                                <tr>
                                    <td>
                                        <div class="company-company-cell">
                                            <strong>{{ $owner['label'] }}</strong>
                                            @if ($owner['meta'])
                                                <span>{{ $owner['meta'] }}</span>
                                            @endif
                                        </div>
                                    </td>
                                    @foreach ($matrix['columns'] as $column)
                                        @php
                                            $row = $owner['cells'][$column['key']] ?? null;
                                        @endphp
                                        <td>
                                            @if ($row)
                                                <div class="company-doc-cell">
                                                    <span class="company-doc-status {{ $row['is_expiring'] ? 'expiring' : $statusClass($row['status']) }}">
                                                        {{ $row['is_expiring'] ? 'In scadenza' : $row['status_label'] }}
                                                    </span>

                                                    @if ($row['notes'])
                                                        <div class="company-doc-note">{{ $row['notes'] }}</div>
                                                    @endif
                                                </div>
                                            @else
                                                <div class="company-doc-cell"></div>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>

    <script>
        (() => {
            function bindDragScroll() {
                document.querySelectorAll('[data-drag-scroll]').forEach((element) => {
                    if (element.dataset.dragScrollBound === 'true') {
                        return;
                    }

                    element.dataset.dragScrollBound = 'true';

                    let isDragging = false;
                    let startX = 0;
                    let startScrollLeft = 0;

                    element.addEventListener('mousedown', (event) => {
                        if (event.button !== 0 || event.target.closest('a, button, input, textarea, select, label')) {
                            return;
                        }

                        isDragging = true;
                        startX = event.pageX;
                        startScrollLeft = element.scrollLeft;
                        element.classList.add('is-dragging');
                        event.preventDefault();
                    });

                    element.addEventListener('mousemove', (event) => {
                        if (!isDragging) {
                            return;
                        }

                        const delta = event.pageX - startX;
                        element.scrollLeft = startScrollLeft - delta;
                    });

                    const stopDragging = () => {
                        isDragging = false;
                        element.classList.remove('is-dragging');
                    };

                    element.addEventListener('mouseleave', stopDragging);
                    element.addEventListener('mouseup', stopDragging);
                    window.addEventListener('mouseup', stopDragging);
                });
            }

            document.addEventListener('DOMContentLoaded', bindDragScroll);
            document.addEventListener('livewire:navigated', bindDragScroll);
        })();
    </script>
</x-filament-panels::page>
