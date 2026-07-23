@extends('layouts.app')
@section('title',$meta['title'].' — Docan')
@section('body-class','pos-body')
@section('content')
@php
    $formatValue = fn (int $value) => $meta['money']
        ? 'Rp '.number_format($value,0,',','.')
        : number_format($value,0,',','.').' item';
    $groupIcons = [
        'provider'=>'<path d="M7 3h10v18H7zM9.5 6h5M9.5 10h5M10 16h.01M14 16h.01"/>',
        'recharge'=>'<path d="m13 2-8 12h7l-1 8 8-12h-7z"/>',
        'wallet'=>'<path d="M4 6h15v12H4a2 2 0 0 1-2-2V7a3 3 0 0 1 3-3h12M15 10h6v4h-6a2 2 0 0 1 0-4z"/>',
        'accessory'=>'<path d="m14.7 6.3 3-3a4 4 0 0 1-5.6 5.6l-6.8 6.8a2 2 0 1 0 3 3l6.8-6.8a4 4 0 0 1 5.6-5.6l-3 3z"/>',
    ];
@endphp
<div class="app-shell report-detail-page">
<header class="flow-header report-detail-header">
    <a href="{{ route('reports.index',['month'=>$periodKey]) }}" aria-label="Kembali ke laporan">←</a>
    <div><small>DETAIL LAPORAN</small><h1>{{ $meta['title'] }}</h1></div>
    @if($meta['periodic'])<span>{{ $period->translatedFormat('M Y') }}</span>@endif
</header>
<main class="report-detail-main">
    @if($group === '')
        <section class="report-detail-intro">
            <span>RINGKASAN</span>
            <h2>{{ $meta['title'] }}</h2>
            <p>{{ $meta['description'] }}. Pilih kelompok untuk membuka rinciannya.</p>
        </section>
        <section class="report-service-grid">
            @foreach($groupMeta as $key=>$item)
                <a href="{{ route('reports.detail',['metric'=>$metric,'month'=>$periodKey,'group'=>$key]) }}">
                    <span><svg viewBox="0 0 24 24">{!! $groupIcons[$key] !!}</svg></span>
                    <div><b>{{ $item['title'] }}</b><strong>{{ $formatValue($item['value']) }}</strong><small>{{ $item['description'] }}</small></div>
                </a>
            @endforeach
        </section>
    @else
        <section class="report-detail-intro report-group-heading">
            <a href="{{ route('reports.detail',['metric'=>$metric,'month'=>$periodKey]) }}">← Kelompok laporan</a>
            <span>RINCIAN {{ mb_strtoupper($meta['short']) }}</span>
            <h2>{{ $groupMeta[$group]['title'] }}</h2>
            <p>{{ $groupMeta[$group]['description'] }}</p>
        </section>
        <section class="provider-stock-grid report-breakdown-grid">
            @foreach($cardsByGroup[$group] as $card)
                <article class="provider-stock-card report-breakdown-card">
                    <div class="provider-stock-title">
                        <span class="provider-summary-logo {{ empty($card['logo']) ? 'all-logo' : '' }}">
                            @if($card['logo'])<img src="{{ asset('img/'.$card['logo']) }}" alt="">@else D @endif
                        </span>
                        <div><b>{{ $card['title'] }}</b><small>{{ $formatValue((int)$card['value']) }}</small></div>
                    </div>
                    <dl>
                        @foreach($card['lines'] as $line)
                            <div><dt>{{ $line['label'] }}</dt><dd>{{ $formatValue((int)$line['value']) }}</dd></div>
                        @endforeach
                    </dl>
                </article>
            @endforeach
        </section>
    @endif
</main>
@include('components.mobile-nav')
</div>
@endsection
