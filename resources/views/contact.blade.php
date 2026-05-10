@extends('layouts.landing')

@section('title', 'Contact — SportSync')

@section('main')
<section class="section-container" id="contact-page">
  <div class="section-header reveal">
    <span class="section-label">CONTACT</span>
    <h2 class="section-title">Get in touch with SportSync</h2>
  </div>

  <div style="max-width:900px;margin:0 auto;display:flex;gap:32px;flex-wrap:wrap;">
    <div style="flex:1;min-width:300px;">
      <div class="sport-card">
        <h3 class="sport-name">Email</h3>
        <p class="form-hint">For general enquiries and support, email us at <a href="mailto:contact@sportssync.example">contact@sportssync.example</a>.</p>
        <h3 class="sport-name" style="margin-top:18px;">Head Office</h3>
        <p class="form-hint">SportSync Ltd.<br>123 Analytics Way<br>City, Country</p>
      </div>
    </div>

    <div style="flex:1;min-width:300px;">
      <div class="sport-card">
        <h3 class="sport-name">Send us a message</h3>
        <form method="POST" action="#" onsubmit="alert('This demo form is not connected. Email: contact@sportssync.example');return false;">
          <label style="display:block;margin-bottom:8px;color:var(--gray-light);">Your name</label>
          <input type="text" name="name" style="width:100%;padding:10px;border-radius:6px;border:1px solid var(--border);margin-bottom:12px;background:transparent;color:var(--white);">
          <label style="display:block;margin-bottom:8px;color:var(--gray-light);">Your email</label>
          <input type="email" name="email" style="width:100%;padding:10px;border-radius:6px;border:1px solid var(--border);margin-bottom:12px;background:transparent;color:var(--white);">
          <label style="display:block;margin-bottom:8px;color:var(--gray-light);">Message</label>
          <textarea name="message" style="width:100%;min-height:120px;padding:10px;border-radius:6px;border:1px solid var(--border);margin-bottom:12px;background:transparent;color:var(--white);"></textarea>
          <button class="btn btn-primary" type="submit">Send message</button>
        </form>
      </div>
    </div>
  </div>
</section>
@endsection
