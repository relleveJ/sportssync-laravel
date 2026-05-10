/**
 * SportsSync — Interactive About Bot  (about_bot.js)
 * Place at: public/about_bot.js
 *
 * Features:
 *  • Bot-driven About SportsSync conversation
 *  • Crew showcase with reactions
 *  • Contributions explorer
 *  • Sports history story mode
 *  • Filipino meme personality
 *  • Typing animation + choice bubbles
 *
 * No external dependencies. Vanilla JS only.
 */
(function () {
  'use strict';

  /* ─────────────────────────────────────────
     DATA
  ───────────────────────────────────────── */
  var CREW = [
    { name: 'Joanner Relleve',    role: 'Team Lead / System Architect', emoji: 'J' },
    { name: 'Kurt Oliver Pagatpat', role: 'Backend (PHP/MySQL)',          emoji: 'K' },
    { name: 'Ludwig Agawin',      role: 'Real-time Sync (SSOT)',          emoji: 'L' },
    { name: 'Realyn Mitra',       role: 'UI/UX Design',                  emoji: 'R' },
    { name: 'Joanna Oademis',     role: 'Frontend Dev',                  emoji: 'J' },
    { name: 'Aliah Lim',          role: 'QA & Testing',                  emoji: 'A' },
    { name: 'Francis Genorilla',  role: 'Data & Analytics',              emoji: 'F' },
  ];

  var CREW_REACTIONS = [
    'Solid to sila ha 😎',
    'Teamwork malala 💯',
    'Dito nagkaalaman kung sino puyat 😭',
    'Respect talaga 🙌',
    'Grabe yung dedication ng taong ito 😤',
    'Buhay pa kaya siya habang ginagawa ito? 😅',
    'Probably drank 10 cups of coffee para dito 😂',
  ];

  var CONTRIBUTIONS = [
    { icon: '🏗️', label: 'System Design',           key: 'design'   },
    { icon: '⚡', label: 'Real-time Sync (SSOT)',   key: 'sync'     },
    { icon: '🎨', label: 'UI/UX',                    key: 'uiux'     },
    { icon: '🐘', label: 'Backend (PHP/MySQL)',       key: 'backend'  },
    { icon: '🧪', label: 'Testing & QA',             key: 'qa'       },
    { icon: '📊', label: 'Analytics',                key: 'analytics'},
  ];

  var CONTRIB_COMMENTS = {
    design:    ['Ito yung pinaka-pinagisipan 😤', 'Dito nagsimula lahat ng gusto at hindi gusto 😅', 'Architecture decisions dito = endless debates 💀'],
    sync:      ['Ito pinaka-critical 😤 Pag nag-break to… GG 😬', 'WebSocket magic ang ginawa nila dito 🔮', 'Real-time = real stress 😭'],
    uiux:      ['Dito nagkanda-ubos ang braincells 😭', 'Revision number 47 yata ito 😅', 'Design is never done, they said 😒'],
    backend:   ['PHP warriors 💪 Hindi sila nagquit kahit kelan', 'MySQL queries na gumawa ng sakit ng ulo 😤', 'Pero ayos, gumagana naman 😎'],
    qa:        ['Sila ang naghahanap ng bugs... at nakakahanap 😬', 'Break everything to fix everything 😂', 'Silent heroes ng lahat ng software 🏅'],
    analytics: ['Data don\'t lie... pero minsan mabagal mag-load 😅', 'Charts, graphs, at sobrang daming numbers 📊', 'Kung may problema sa stats, tanungin ito 😎'],
  };

  var STORIES = [
    {
      title: '🏀 Basketball: Born from Boredom',
      lines: [
        'Alam mo ba… yung basketball, naimbento lang dahil bored sila? 😭',
        'Si Dr. James Naismith — isang Canadian — ginawa niya ito noong 1891.',
        'Yung original na basket? Literal na peach basket. Hindi nagbubukas sa ilalim. 😂',
        'Ibig sabihin… pagkatapos ng bawat score, may kumuha ng bola sa loob ng basket. Manually. 😭',
        'Tapos ayun… naging global sport. Grabe yung glow up 😳⚡',
      ],
    },
    {
      title: '🏐 Volleyball: The "Chill" Sport na Hindi Pala Chill',
      lines: [
        'Iniimbento ni William G. Morgan noong 1895 para sa mga "mas matanda" na tao 😅',
        'Sabi niya, basketball daw ay "too intense".',
        'Kaya gumawa siya ng sport na may net at hindi pwedeng hawakan yung bola.',
        'Tapos ngayon… pro volleyball players? Nagta-train ng 8 hours a day 😤',
        'Chill sport daw. Chill. 😒',
      ],
    },
    {
      title: '⚡ The Day Sports Changed Forever',
      lines: [
        'Noong 1992 Barcelona Olympics — NBA players pinahintulutan na lumaro.',
        'Nag-assemble yung "Dream Team": Jordan, Magic, Bird, Barkley…',
        'Average na panalo nila: 43.8 points. AVERAGE. 😳',
        'Yung opponent nila sa first game? Puerto Rico. Score: 116-48. 💀',
        'Yung larong ito ang nagpalit ng basketball globally forever. 🌍⚡',
      ],
    },
    {
      title: '🏓 Table Tennis: Olympic in Pajamas',
        lines: [
        'Table tennis nagsimula bilang after-dinner game ng mga British aristocrats noong 1880s.',
        'Ginamit nila yung mga libro bilang net. Mga cork bilang bola. 😂',
        'Tapos naging Olympics sport siya noong 1988.',
        'Ang China? Nanalo ng 28 out of 32 gold medals sa Olympics. 28. 😤',
        'From pajama game hanggang Olympic domination. Respek. 🏅',
      ],
    },
    {
      title: '🎯 Darts: Pub Game Goes Pro',
      lines: [
        'Darts! Ang sport na paborito ng lahat ng may beer sa kamay. 🍺',
        'Nagsimula siya sa medieval England — soldiers nagtatapon ng arrows sa wine barrels.',
        'Noong 1908 isang court case ang nagpasya na darts is a "game of skill, not chance".',
        'Kung hindi niya nanalo ang case? Illegal na sana ang darts sa UK. 😱',
        'Ngayon, may pro players na kumikita ng millions. From pub to paychecks. 💰😎',
      ],
    },
  ];

  var BOT_REACTIONS = [
    'ANU BA 😭',
    'Click mo pa, di pa tapos kwento ko 😤',
    'WAIT LANG, may plot twist pa 👀',
    'Sige na nga… next na 😒',
    'Ay grabe ka 😭 tuloy mo lang',
  ];

  /* ─────────────────────────────────────────
     STATE
  ───────────────────────────────────────── */
  var isOpen        = false;
  var isTyping      = false;
  var currentTab    = 'about';  // about | crew | contrib | story
  var storyIndex    = 0;
  var storyLineIdx  = 0;
  var typingQueue   = [];
  var processingQ   = false;

  /* ─────────────────────────────────────────
     DOM
  ───────────────────────────────────────── */
  var overlay, modal, chat, avatarEl, openBtn;

  /* ─────────────────────────────────────────
     UTILS
  ───────────────────────────────────────── */
  function rand(arr) { return arr[Math.floor(Math.random() * arr.length)]; }
  function randInt(a,b){ return Math.floor(Math.random()*(b-a+1))+a; }

  function mdToHtml(t) {
    return (t||'')
      .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
      .replace(/\*(.+?)\*/g,     '<em>$1</em>');
  }

  function scrollBottom() {
    if (chat) chat.scrollTop = chat.scrollHeight;
  }

  /* ─────────────────────────────────────────
     TYPING QUEUE — serialises all messages
     so they appear one at a time with
     realistic delays.
  ───────────────────────────────────────── */
  function enqueue(fn, delay) {
    typingQueue.push({ fn: fn, delay: delay || 0 });
    if (!processingQ) drainQueue();
  }

  function drainQueue() {
    if (typingQueue.length === 0) { processingQ = false; return; }
    processingQ = true;
    var item = typingQueue.shift();
    setTimeout(function(){
      item.fn();
      drainQueue();
    }, item.delay);
  }

  /* ─────────────────────────────────────────
     RENDER HELPERS
  ───────────────────────────────────────── */
  function showTypingDots(durationMs) {
    return new Promise(function(resolve){
      var d = document.createElement('div');
      d.className = 'ab-typing';
      d.id = 'ab-typing-dots';
      d.innerHTML = '<span></span><span></span><span></span>';
      chat.appendChild(d);
      scrollBottom();
      setTimeout(function(){
        var old = document.getElementById('ab-typing-dots');
        if (old) old.remove();
        resolve();
      }, durationMs || 800);
    });
  }

  function botMsg(html, extraClass) {
    var wrap = document.createElement('div');
    wrap.className = 'ab-msg ab-msg-bot';
    var bub  = document.createElement('div');
    bub.className  = 'ab-bubble' + (extraClass ? ' '+extraClass : '');
    bub.innerHTML  = mdToHtml(html);
    wrap.appendChild(bub);
    chat.appendChild(wrap);
    scrollBottom();
    return wrap;
  }

  function userMsg(text) {
    var wrap = document.createElement('div');
    wrap.className = 'ab-msg ab-msg-user';
    var bub  = document.createElement('div');
    bub.className = 'ab-bubble';
    bub.textContent = text;
    wrap.appendChild(bub);
    chat.appendChild(wrap);
    scrollBottom();
  }

  function reaction(emoji) {
    var r = document.createElement('div');
    r.className   = 'ab-reaction ab-msg';
    r.textContent = emoji;
    chat.appendChild(r);
    scrollBottom();
  }

  function separator(text) {
    var s = document.createElement('div');
    s.className   = 'ab-sep ab-msg';
    s.textContent = text || '· · ·';
    chat.appendChild(s);
    scrollBottom();
  }

  function addChoices(items, onPick) {
    var wrap = document.createElement('div');
    wrap.className = 'ab-choices ab-msg';
    items.forEach(function(item){
      var btn = document.createElement('button');
      btn.className   = 'ab-choice';
      btn.textContent = item.label;
      btn.addEventListener('click', function(){
        // Mark all as used
        wrap.querySelectorAll('.ab-choice').forEach(function(b){
          b.classList.add('ab-used');
        });
        userMsg(item.label);
        onPick(item);
      });
      wrap.appendChild(btn);
    });
    chat.appendChild(wrap);
    scrollBottom();
  }

  function addContribList(items, onPick) {
    var wrap = document.createElement('div');
    wrap.className = 'ab-contrib-list ab-msg';
    items.forEach(function(item){
      var row = document.createElement('div');
      row.className = 'ab-contrib-item';
      row.innerHTML  =
        '<span class="ab-contrib-icon">'+item.icon+'</span>'+
        '<span class="ab-contrib-label">'+item.label+'</span>'+
        '<span class="ab-contrib-arrow">→</span>';
      row.addEventListener('click', function(){
        userMsg(item.label);
        onPick(item);
      });
      wrap.appendChild(row);
    });
    chat.appendChild(wrap);
    scrollBottom();
  }

  function addCrewGrid(crew) {
    var grid = document.createElement('div');
    grid.className = 'ab-crew-grid ab-msg';
    crew.forEach(function(m, i){
      var card = document.createElement('div');
      card.className = 'ab-crew-card';
      card.style.animationDelay = (i * 60)+'ms';
      card.innerHTML =
        '<div class="ab-crew-avatar">'+m.emoji+'</div>'+
        '<div><div class="ab-crew-name">'+m.name+'</div>'+
        '<div class="ab-crew-role">'+m.role+'</div></div>';
      card.addEventListener('click', function(){
        userMsg(m.name);
        queueBotMsg(rand(CREW_REACTIONS), 600, true);
      });
      grid.appendChild(card);
    });
    chat.appendChild(grid);
    scrollBottom();
  }

  /* ─────────────────────────────────────────
     QUEUE WRAPPERS
  ───────────────────────────────────────── */
  function queueBotMsg(html, delay, withTyping, extraClass) {
    delay = delay || 400;
    var typeDur = withTyping ? randInt(500, 900) : 0;

    enqueue(function(){
      if (withTyping) {
        var d = document.createElement('div');
        d.className = 'ab-typing'; d.id = 'ab-typing-dots';
        d.innerHTML = '<span></span><span></span><span></span>';
        chat.appendChild(d);
        scrollBottom();
      }
    }, delay);

    if (withTyping) {
      enqueue(function(){
        var old = document.getElementById('ab-typing-dots');
        if (old) old.remove();
        botMsg(html, extraClass);
      }, typeDur);
    } else {
      enqueue(function(){ botMsg(html, extraClass); }, 0);
    }
  }

  function queueAction(fn, delay) {
    enqueue(fn, delay || 400);
  }

  /* ─────────────────────────────────────────
     AVATAR MOOD
  ───────────────────────────────────────── */
  function setAvatar(emoji) {
    if (avatarEl) avatarEl.textContent = emoji;
  }

  function avatarReact(emoji, durationMs) {
    setAvatar(emoji);
    setTimeout(function(){ setAvatar('⚡'); }, durationMs || 2000);
  }

  /* ─────────────────────────────────────────
     CLEAR CHAT
  ───────────────────────────────────────── */
  function clearChat() {
    if (chat) chat.innerHTML = '';
    typingQueue  = [];
    processingQ  = false;
  }

  /* ─────────────────────────────────────────
     TAB SYSTEM
  ───────────────────────────────────────── */
  function activateTab(key) {
    currentTab = key;
    document.querySelectorAll('.ab-tab').forEach(function(t){
      t.classList.toggle('ab-tab-active', t.dataset.tab === key);
    });
    clearChat();
    switch(key) {
      case 'about':   startAbout();  break;
      case 'crew':    startCrew();   break;
      case 'contrib': startContrib();break;
      case 'story':   startStory();  break;
    }
  }

  /* ─────────────────────────────────────────
     FLOW: ABOUT
  ───────────────────────────────────────── */
  function startAbout() {
    setAvatar('😏');

    queueBotMsg('HOY! Curious ka about SportsSync? 😏', 0, true);
    queueBotMsg('Sige, kwento ko sa iyo… 👇', 200, true);
    queueBotMsg('So — ano ba talaga ang <strong>SportsSync</strong>? 🤔', 400, true);

    queueAction(function(){
      addChoices([
        { label: '🤔 Ano siya exactly?',    id: 'what'    },
        { label: '⚡ Ano ang kaya niya?',   id: 'can'     },
        { label: '👥 Para kanino ito?',     id: 'who'     },
        { label: '🔧 Paano gumagana?',      id: 'how'     },
      ], handleAboutChoice);
    }, 600);
  }

  function handleAboutChoice(item) {
    avatarReact('🤔', 1500);

    if (item.id === 'what') {
      queueBotMsg('OK so…', 200, true);
      queueBotMsg('SportsSync ay isang <strong>real-time sports management system</strong>. 🏆', 300, true);
      queueBotMsg('Think of it as isang hub — lahat ng scores, players, at results? <strong>Dito lahat.</strong> ⚡', 400, true);
      queueBotMsg('At real-time siya. Ibig sabihin — walang F5. Automatic mag-update. 😎', 300, true);
      queueAction(function(){ addMoreAboutChoices(); }, 500);

    } else if (item.id === 'can') {
      queueBotMsg('Kaya niya ang marami, boss. 😤', 200, true);
      queueBotMsg('🏀 Live scoring — basketball, volleyball, badminton, table tennis, darts', 300, true);
      queueBotMsg('📊 Analytics — player stats, match history, performance trends', 300, true);
      queueBotMsg('📋 Match reports — auto-generated pagkatapos ng laro', 300, true);
      queueBotMsg('👤 Player profiles — tracks each player across all matches', 300, true);
      queueBotMsg('At lahat ng ito? <strong>Real-time. Walang manual refresh.</strong> 😏', 400, true);
      queueAction(function(){ addMoreAboutChoices(); }, 500);

    } else if (item.id === 'who') {
      queueBotMsg('Para sa tatlong uri ng tao: 👇', 200, true);
      queueBotMsg('🔑 <strong>Admins</strong> — sila ang nagco-control. Scores, players, everything.', 300, true);
      queueBotMsg('📺 <strong>Viewers</strong> — nakaka-watch ng live scores kahit wala sa venue.', 300, true);
      queueBotMsg('🏅 <strong>Scorekeepers</strong> — sila ang naglalagay ng scores sa live game.', 300, true);
      queueBotMsg('Tig-isa silang role, pero lahat connected sa iisang system. SSOT. 😎', 400, true);
      queueAction(function(){ addMoreAboutChoices(); }, 500);

    } else if (item.id === 'how') {
      queueBotMsg('Medyo technical pero kaya mo yan 😆', 200, true);
      queueBotMsg('Step 1: Admin or scorekeeper nag-eenter ng score sa live UI. ✍️', 300, true);
      queueBotMsg('Step 2: System mag-save agad sa database — <strong>Single Source of Truth (SSOT)</strong>. 🗄️', 300, true);
      queueBotMsg('Step 3: Lahat ng connected viewers? <strong>Automatic nang nag-update.</strong> 🔄', 300, true);
      queueBotMsg('Step 4: Post-match — reports generated automatically. 📋', 300, true);
      queueBotMsg('Walang manual sync. Walang delay. Grabe diba? 😏', 400, true);
      queueAction(function(){ addMoreAboutChoices(); }, 500);
    }
  }

  function addMoreAboutChoices() {
    reaction('😎');
    addChoices([
      { label: '👥 Meet the Crew',       id: 'crew'    },
      { label: '🔧 See Contributions',   id: 'contrib' },
      { label: '📖 Tell me a story',     id: 'story'   },
      { label: '🔁 Ask something else',  id: 'more'    },
    ], function(item){
      if (item.id === 'crew')    { activateTab('crew');    return; }
      if (item.id === 'contrib') { activateTab('contrib'); return; }
      if (item.id === 'story')   { activateTab('story');   return; }
      // more
      clearChat();
      startAbout();
    });
  }

  /* ─────────────────────────────────────────
     FLOW: CREW
  ───────────────────────────────────────── */
  function startCrew() {
    setAvatar('🔥');

    queueBotMsg('Sige, ipakikilala ko sila sa iyo! 😤', 0, true);
    queueBotMsg('🔥 <strong>Behind this system…</strong> Built by:',  300, true);

    queueAction(function(){
      addCrewGrid(CREW);
      reaction('💪');
    }, 500);

    queueBotMsg('I-click ang pangalan para makita ang reaction! 😏', 600, true);
    queueBotMsg(rand(CREW_REACTIONS), 400, true);

    queueAction(function(){
      addChoices([
        { label: '🔧 Sino gumawa ng ano?',  id: 'contrib' },
        { label: '📖 May kwento pa?',        id: 'story'   },
        { label: '← Balik sa About',         id: 'about'   },
      ], function(item){
        activateTab(item.id === 'contrib' ? 'contrib' : item.id === 'story' ? 'story' : 'about');
      });
    }, 500);
  }

  /* ─────────────────────────────────────────
     FLOW: CONTRIBUTIONS
  ───────────────────────────────────────── */
  function startContrib() {
    setAvatar('🛠️');

    queueBotMsg('Eto ang mga major parts ng SportsSync! 🛠️', 0, true);
    queueBotMsg('I-click ang bawat item para malaman ang <em>tea</em> nila 😏', 300, true);

    queueAction(function(){
      addContribList(CONTRIBUTIONS, handleContribClick);
    }, 400);
  }

  function handleContribClick(item) {
    avatarReact('🤯', 2000);
    var comments = CONTRIB_COMMENTS[item.key] || ['Grabe to 😤'];
    queueBotMsg(item.icon + ' <strong>' + item.label + '</strong>', 200, true, 'ab-bubble-highlight');
    queueBotMsg(rand(comments), 300, true);

    queueAction(function(){
      addChoices([
        { label: '🔍 Tingnan ang isa pa', id: 'more' },
        { label: '👥 Meet the Crew',       id: 'crew' },
        { label: '📖 Tell me a story',     id: 'story'},
      ], function(c){
        if (c.id === 'more') {
          queueBotMsg('Sige, pili ulit! 😏', 200, true);
          queueAction(function(){ addContribList(CONTRIBUTIONS, handleContribClick); }, 400);
        } else {
          activateTab(c.id === 'crew' ? 'crew' : 'story');
        }
      });
    }, 400);
  }

  /* ─────────────────────────────────────────
     FLOW: STORY MODE
  ───────────────────────────────────────── */
  function startStory() {
    setAvatar('📖');
    storyIndex   = randInt(0, STORIES.length - 1);
    storyLineIdx = 0;

    queueBotMsg('Ohhh story time! 📖 Sit down, makinig ka muna! 😤', 0, true);
    queueBotMsg(rand(BOT_REACTIONS), 300, true);

    queueAction(function(){
      playStory(storyIndex);
    }, 500);
  }

  function playStory(idx) {
    var story = STORIES[idx];
    storyLineIdx = 0;

    // Story title card
    queueAction(function(){
      var card = document.createElement('div');
      card.className = 'ab-story-card ab-msg';
      card.innerHTML =
        '<div class="ab-story-title">'+story.title+'</div>'+
        '<div class="ab-story-body" id="ab-story-body"></div>';
      chat.appendChild(card);
      scrollBottom();
      // drip-feed the story lines
      dripStoryLines(story.lines, document.getElementById('ab-story-body') || card.querySelector('.ab-story-body'));
    }, 200);
  }

  function dripStoryLines(lines, container) {
    var i = 0;
    function next(){
      if (i >= lines.length) {
        // End of story choices
        queueAction(function(){
          reaction('😳');
          addChoices([
            { label: '➡️ Next story',       id: 'next'   },
            { label: '🎲 Random story',     id: 'random' },
            { label: '← Back to About',     id: 'about'  },
          ], function(c){
            clearChat();
            if (c.id === 'about') { activateTab('about'); return; }
            storyIndex = (c.id === 'next')
              ? (storyIndex + 1) % STORIES.length
              : randInt(0, STORIES.length - 1);
            startStory();
          });
        }, 600);
        return;
      }

      var line    = lines[i]; i++;
      var delay   = 600 + Math.min(line.length * 12, 1200);

      enqueue(function(){
        // typing dots briefly
        var dotWrap = document.createElement('span');
        dotWrap.style.display = 'inline-block';
        dotWrap.textContent = ' ⏳';
        container.appendChild(dotWrap);
        scrollBottom();
        setTimeout(function(){
          dotWrap.remove();
          var p = document.createElement('p');
          p.style.margin = '4px 0';
          p.style.lineHeight = '1.6';
          p.innerHTML = mdToHtml(line);
          container.appendChild(p);
          scrollBottom();
          next();
        }, delay);
      }, 300);
    }
    next();
  }

  /* ─────────────────────────────────────────
     MODAL OPEN / CLOSE
  ───────────────────────────────────────── */
  function openModal() {
    if (isOpen) return;
    isOpen = true;
    overlay.classList.add('ab-visible');
    if (chat.children.length === 0) {
      activateTab('about');
    }
  }

  function closeModal() {
    if (!isOpen) return;
    isOpen = false;
    overlay.classList.remove('ab-visible');
  }

  /* ─────────────────────────────────────────
     INIT
  ───────────────────────────────────────── */
  function init() {
    overlay   = document.getElementById('ab-overlay');
    modal     = document.getElementById('ab-modal');
    chat      = document.getElementById('ab-chat');
    avatarEl  = document.getElementById('ab-avatar-face-modal');
    openBtn   = document.getElementById('ab-open-btn');

    if (!overlay || !openBtn) return;

    // Open button
    openBtn.addEventListener('click', openModal);

    // Close button
    var closeBtn = document.getElementById('ab-close-modal');
    if (closeBtn) closeBtn.addEventListener('click', closeModal);

    // Backdrop click
    overlay.addEventListener('click', function(e){
      if (e.target === overlay) closeModal();
    });

    // Tabs
    document.querySelectorAll('.ab-tab').forEach(function(tab){
      tab.addEventListener('click', function(){
        activateTab(tab.dataset.tab);
      });
    });

    // Escape key
    document.addEventListener('keydown', function(e){
      if (e.key === 'Escape' && isOpen) closeModal();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();