@extends('layouts.app')

@section('content')
<div class="py-12">
  <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
    <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
      <h1 class="text-2xl font-bold mb-4">Users</h1>
      @if(session('success'))<div class="mb-3 text-green-600">{{ session('success') }}</div>@endif
      @if(session('error'))<div class="mb-3 text-red-600">{{ session('error') }}</div>@endif
      <table class="min-w-full table-auto">
        <thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Role</th><th></th></tr></thead>
        <tbody>
        @foreach($users as $u)
          <tr>
            <td>{{ $u->id }}</td>
            <td>{{ $u->name }}</td>
            <td>{{ $u->email }}</td>
            <td>{{ $u->role }}</td>
            <td>
              @if($u->role !== 'superadmin')
              <form method="POST" action="{{ route('superadmin.users.promote') }}" style="display:inline">
                @csrf
                <input type="hidden" name="user_id" value="{{ $u->id }}">
                <button class="px-3 py-1 bg-yellow-500 text-white rounded">Promote to superadmin</button>
              </form>
              @else
                <span class="text-gray-500">—</span>
              @endif
            </td>
          </tr>
        @endforeach
        </tbody>
      </table>
    </div>
  </div>
</div>
@endsection
