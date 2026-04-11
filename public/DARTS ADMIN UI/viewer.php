<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Live Darts Viewer</title>
    <style>
        body { font-family: 'Arial Black', sans-serif; background: #000; color: #fff; text-align: center; padding: 20px; }
        .grid { display: flex; justify-content: center; gap: 40px; margin-top: 50px; }
        .card { background: #1a1a1a; border: 4px solid #333; border-radius: 15px; width: 300px; padding: 20px; }
        .score { font-size: 100px; color: #ff0; margin: 20px 0; }
        .chip { display: inline-block; background: #444; padding: 5px 15px; margin: 5px; font-size: 20px; border-radius: 5px; }
    </style>
</head>
<body>
    <h1>SPORTSSYNC LIVE DARTS</h1>
    <div style="margin-top:10px"><a href="../landingpage.php" style="color:#FFD700;text-decoration:none;font-weight:bold">← Back to sports</a></div>
    <div class="grid" id="board">Waiting for match data...</div>

    <script>
        async function fetchLive() {
            try {
                let r = await fetch('state.php');
                let j = await r.json();
                if (j.live_state) {
                    let state = JSON.parse(j.live_state);
                    document.getElementById('board').innerHTML = state.players.map(p => `
                        <div class="card">
                            <h2>${p.name}</h2>
                            <div class="score">${p.score}</div>
                            <h3>Legs Won: ${p.legs}</h3>
                            <div>${p.hist.slice(-3).map(h => `<span class="chip">${h.bust ? 'BUST' : h.v}</span>`).join('')}</div>
                        </div>
                    `).join('');
                }
            } catch (e) {}
        }
        setInterval(fetchLive, 2000); // Polls every 2 seconds
        fetchLive();
    </script>
</body>
</html>