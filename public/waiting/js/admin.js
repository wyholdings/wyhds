const adminQueue = document.getElementById('adminQueue');
const queueSummary = document.getElementById('queueSummary');
const statWaiting = document.getElementById('statWaiting');
const statCalling = document.getElementById('statCalling');
const statSeated = document.getElementById('statSeated');
const clock = document.getElementById('clock');
const toast = document.getElementById('toast');
const storeNameEl = document.getElementById('storeName');
const loginView = document.getElementById('loginView');
const storeSetup = document.getElementById('storeSetup');
const adminView = document.getElementById('adminView');
const loginForm = document.getElementById('loginForm');
const pinInput = document.getElementById('pin');
const storeForm = document.getElementById('storeForm');
const storeNameInput = document.getElementById('storeNameInput');
const storePhoneInput = document.getElementById('storePhoneInput');
const storeAddrInput = document.getElementById('storeAddrInput');
const storeNoticeInput = document.getElementById('storeNoticeInput');

const ADMIN_AUTH_KEY = 'admin_authed';
const ADMIN_PIN = '1234'; // 필요 시 원하는 값으로 변경
const API_BASE = '/waiting/api';

let waitlist = [];
let started = false;

function showToast(message, tone = 'positive') {
  if (!toast) return;
  toast.textContent = message;
  toast.classList.remove('positive', 'negative', 'show');
  toast.classList.add(tone === 'negative' ? 'negative' : 'positive');
  requestAnimationFrame(() => toast.classList.add('show'));
  setTimeout(() => toast.classList.remove('show'), 2200);
}

async function fetchStoreInfo() {
  const fallback = 'Blue Harbour Dining';
  if (!storeNameEl) return;
  try {
    const res = await fetch(`${API_BASE}/store-info.php`);
    if (res.status === 404) {
      showView('setup');
      return;
    }
    if (!res.ok) throw new Error('bad response');
    const data = await res.json();
    storeNameEl.textContent = data?.name || fallback;
    showView('main');
    renderQueue();
  } catch (e) {
    storeNameEl.textContent = fallback;
    showToast('업체 정보를 불러올 수 없습니다.', 'negative');
  }
}

function showView(key) {
  const map = { login: loginView, setup: storeSetup, main: adminView };
  Object.values(map).forEach((el) => el && el.classList.remove('active'));
  if (map[key]) map[key].classList.add('active');
}

function handleLogin() {
  if (!loginForm) return;
  loginForm.addEventListener('submit', (e) => {
    e.preventDefault();
    const pin = (pinInput?.value || '').trim();
    if (pin !== ADMIN_PIN) {
      showToast('비밀번호가 올바르지 않습니다.', 'negative');
      return;
    }
    localStorage.setItem(ADMIN_AUTH_KEY, '1');
    showToast('로그인 완료');
    startAdminFlow();
  });
}

function handleStoreSave() {
  if (!storeForm) return;
  storeForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const name = (storeNameInput?.value || '').trim();
    if (!name) {
      showToast('업체명을 입력해주세요.', 'negative');
      return;
    }
    const body = {
      name,
      phone: (storePhoneInput?.value || '').trim(),
      address: (storeAddrInput?.value || '').trim(),
      notice: (storeNoticeInput?.value || '').trim(),
    };
    try {
      const res = await fetch(`${API_BASE}/store-info.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      });
      if (!res.ok) throw new Error('save failed');
      const data = await res.json();
      storeNameEl.textContent = data?.name || name;
      showToast('업체정보가 저장되었습니다.');
      showView('main');
      renderQueue();
    } catch (err) {
      showToast('저장에 실패했습니다.', 'negative');
    }
  });
}

function renderQueue() {
  adminQueue.innerHTML = '';

  const waitingCount = waitlist.filter((w) => w.status === 'waiting').length;
  const callingCount = waitlist.filter((w) => w.status === 'calling').length;
  const seatedCount = waitlist.filter((w) => w.status === 'seated').length;

  statWaiting.textContent = waitingCount;
  statCalling.textContent = callingCount;
  statSeated.textContent = seatedCount;

  if (waitlist.length === 0) {
    queueSummary.textContent = '현재 대기팀이 없습니다.';
    adminQueue.innerHTML = '<div class="notice">등록된 팀이 없습니다.</div>';
    return;
  }

  queueSummary.textContent = `총 ${waitlist.length}팀 · 대기 ${waitingCount}팀`;

  waitlist.forEach((item) => {
    const adminCard = document.createElement('div');
    adminCard.className = 'ticket';
    adminCard.innerHTML = `
      <div>
        <strong>대기번호 ${item.ticket}번 · ${item.people}명</strong><br>
        <small>${item.phone} · 등록 ${item.created_at ?? ''}</small>
      </div>
      <div style="display:flex; gap:6px; align-items:center; flex-wrap:wrap; justify-content:flex-end;">
        <span class="status ${item.status === 'waiting' ? 'waiting' : item.status === 'calling' ? 'called' : 'seated'}">
          ${item.status === 'waiting' ? '대기' : item.status === 'calling' ? '호출중' : '완료'}
        </span>
        ${item.status === 'waiting' ? '<button class="btn btn-primary" style="padding:10px 12px;" data-action="call">호출</button>' : ''}
        ${item.status !== 'seated' ? '<button class="btn btn-secondary" style="padding:10px 12px;" data-action="seat">입장</button>' : ''}
        ${item.status !== 'seated' ? '<button class="btn btn-secondary" style="padding:10px 12px; background: rgba(255,123,138,0.12); border-color: rgba(255,123,138,0.4);" data-action="cancel">미응답/취소</button>' : ''}
      </div>
    `;

    adminCard.querySelectorAll('button').forEach((btn) => {
      btn.onclick = async () => {
        const action = btn.dataset.action;
        const newStatus = action === 'call' ? 'calling' : action === 'seat' ? 'seated' : 'cancelled';
        try {
          const res = await fetch(`${API_BASE}/reservation-status.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: item.id, status: newStatus }),
          });
          if (!res.ok) throw new Error('update failed');
          if (action === 'call') {
            showToast(`대기번호 ${item.ticket}번 호출했습니다.`);
          } else if (action === 'seat') {
            showToast(`대기번호 ${item.ticket}번 입장 처리 완료`);
          } else {
            showToast(`대기번호 ${item.ticket}번 대기를 취소했습니다.`);
          }
          await fetchQueue(true);
        } catch (err) {
          showToast('상태 변경에 실패했습니다.', 'negative');
        }
      };
    });

    adminQueue.appendChild(adminCard);
  });
}

async function fetchQueue(isAdmin = false) {
  try {
    const res = await fetch(`${API_BASE}/reservations.php?role=${isAdmin ? 'admin' : 'guest'}`);
    if (!res.ok) throw new Error('bad response');
    const data = await res.json();
    waitlist = data?.items || [];
    renderQueue();
  } catch (e) {
    showToast('대기 목록을 불러오지 못했습니다.', 'negative');
  }
}

function tickClock() {
  const now = new Date();
  const hh = String(now.getHours()).padStart(2, '0');
  const mm = String(now.getMinutes()).padStart(2, '0');
  clock.textContent = `${hh}:${mm}`;
}

function startAdminFlow() {
  if (started) return;
  started = true;
  fetchStoreInfo();
  fetchQueue(true);
  tickClock();
  setInterval(tickClock, 30000);
  setInterval(() => fetchQueue(true), 10000);
}

handleLogin();
handleStoreSave();

if (localStorage.getItem(ADMIN_AUTH_KEY) === '1') {
  startAdminFlow();
} else {
  showView('login');
}
