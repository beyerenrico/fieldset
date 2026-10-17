{{-- 410 Gone, e.g. the PDF of an anonymized submission (ADR 0039): shows the user-facing abort() message, escaped. --}}
@extends('errors::minimal')

@section('title', __('Gone'))
@section('code', '410')
@section('message', isset($exception) && $exception->getMessage() !== '' ? $exception->getMessage() : __('Gone'))
