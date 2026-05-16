@php
    $groupedReferenceDocs = collect($referenceDocs)->groupBy('group');
@endphp

<div class="sdk-docs-nav-group">
    <p class="sdk-docs-nav-label">Manual</p>
    <a class="sdk-docs-nav-link{{ ($active ?? '') === 'overview' ? ' is-active' : '' }}" href="{{ route('sdk.public.index') }}">
        <span>Overview</span>
        <small>Public SDK reference index.</small>
    </a>
    <a class="sdk-docs-nav-link{{ ($active ?? '') === 'backend' ? ' is-active' : '' }}" href="{{ route('sdk.public.backend') }}">
        <span>Backend SDK</span>
        <small>Trusted admission and token issuance.</small>
    </a>
</div>

<div class="sdk-docs-nav-group">
    <p class="sdk-docs-nav-label">Tutorials</p>
    @foreach ($tutorials as $slug => $tutorial)
        <a class="sdk-docs-nav-link{{ ($active ?? '') === $slug ? ' is-active' : '' }}" href="{{ route('sdk.public.tutorials.show', ['tutorial' => $slug]) }}">
            <span>{{ $tutorial['title'] }}</span>
            <small>{{ $tutorial['summary'] }}</small>
        </a>
    @endforeach
</div>

@foreach ($groupedReferenceDocs as $group => $docs)
    <div class="sdk-docs-nav-group">
        <p class="sdk-docs-nav-label">{{ $group }}</p>
        @foreach ($docs as $id => $doc)
            <a class="sdk-docs-nav-link{{ ($active ?? '') === $id ? ' is-active' : '' }}" href="{{ route('sdk.public.reference', ['doc' => $id]) }}">
                <span>{{ $doc['title'] }}</span>
                <small>{{ $doc['summary'] }}</small>
            </a>
        @endforeach
    </div>
@endforeach
