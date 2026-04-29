<tr class="{{ $isChild ? 'docs-row-child' : '' }}">
    <td>
        <div class="docs-name">
            {{ $isChild ? '↳ ' : '' }}{{ $row['name'] }}
            @if ($row['optional'])
                <span class="docs-child-label">Opzionale</span>
            @endif
        </div>
        @if ($row['uploaded_at'])
            <div class="docs-meta">Caricato il {{ $row['uploaded_at'] }}</div>
        @endif
    </td>
    <td>
        <span class="docs-status {{ $row['is_expiring'] ? 'expiring' : $statusClass($row['status']) }}">
            {{ $row['is_expiring'] ? 'In scadenza' : $row['status_label'] }}
        </span>
    </td>
    <td>
        @if ($row['expiry_date'])
            <div>Scadenza: {{ $row['expiry_date'] }}</div>
        @endif
        @if ($row['internal_expiry'])
            <div class="docs-meta">{{ $row['internal_expiry'] }}</div>
        @endif
        @if (! $row['expiry_date'] && ! $row['internal_expiry'])
            <span class="docs-meta">-</span>
        @endif
    </td>
    <td>
        @if ($row['notes'])
            <div class="docs-note">{{ $row['notes'] }}</div>
        @else
            <span class="docs-meta">-</span>
        @endif
    </td>
    <td>
        <div class="docs-actions">
            @if ($row['download_url'])
                <a class="docs-action" href="{{ $row['download_url'] }}" target="_blank" rel="noreferrer">Scarica</a>
            @endif
            @if ($row['review_url'])
                <a class="docs-action" href="{{ $row['review_url'] }}">Revisiona</a>
            @endif
            @if (! $row['download_url'] && ! $row['review_url'])
                <span class="docs-meta">-</span>
            @endif
        </div>
    </td>
</tr>
