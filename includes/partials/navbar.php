<?php $me = $me ?? Auth::user(); $role = Auth::role(); ?>
<nav class="navbar">
  <div class="nav-brand">✚ DOKTA-RDV 
    <?php if($role==='super_admin'): ?><span class="role-badge role-super">Super Admin</span>
    <?php elseif($role==='admin_hopital'): ?><span class="role-badge role-admin"><?=htmlspecialchars($me['hopital_nom']??'')?></span>
    <?php elseif($role==='medecin'): ?><span class="role-badge role-medecin">Dr. <?=htmlspecialchars($me['nom']??'')?></span>
    <?php endif; ?>
  </div>
  <div class="nav-right">
    <span class="nav-user">👤 <?=htmlspecialchars($me['prenom'].' '.$me['nom'])?></span>
    <a href="../logout.php" class="btn btn-outline btn-sm">Déconnexion</a>
  </div>
</nav>
