@extends('layouts.app')

@section('content')
<div class="py-12">
  <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
      <h1 class="text-2xl font-bold mb-4">Superadmin Console</h1>
      <p class="mb-4">Full access to all modules. Use the links below to manage users and system features.</p>
      <div class="space-x-2">
        <a class="px-4 py-2 bg-blue-600 text-white rounded" href="{{ route('superadmin.users') }}">Manage Users</a>
        <a class="px-4 py-2 bg-gray-600 text-white rounded" href="{{ route('legacy.adminlanding') }}">Legacy Admin Landing</a>
      </div>
    </div>
  </div>
</div>
@endsection
