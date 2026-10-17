{{--
    410 Gone (the PDF of an anonymized submission, ADR 0039; a used invitation; an expired document link): the
    user-facing abort() message, escaped, or a generic one. Signed-in users and invitees get a way back: the admin
    dashboard, or the Field App for members without admin access. Customers on an expired mail link get none.
--}}
@extends('errors::minimal')

@php
    $viewer = auth()->user();
    $toFieldApp = $viewer instanceof \App\Domain\Identity\Models\User
        && ! $viewer->canAccessPanel(\Filament\Facades\Filament::getPanel('admin'));
@endphp

@section('title', __('errors.gone.title'))
@section('code', '410')
@section('message', isset($exception) && $exception->getMessage() !== '' ? $exception->getMessage() : __('errors.gone.message'))

@if ($viewer !== null || request()->routeIs('invitations.*'))
    @section('actions')
        <a href="{{ $toFieldApp ? url('/app') : url('/admin') }}">{{ $toFieldApp ? __('errors.back_to_app') : __('errors.back_to_dashboard') }}</a>
    @endsection
@endif
