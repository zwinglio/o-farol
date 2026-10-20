// pm2 (CloudPanel): mantém o front rodando com Bun.
//   pm2 startOrRestart ~/o-farol/deploy/pm2.config.cjs && pm2 save
// O deploy/front.sh chama isso via FRONT_RESTART (ver DEPLOY.md, seção 2.6).
const home = process.env.HOME;
const base = process.env.FRONT_BASE || `${home}/front`;

module.exports = {
  apps: [
    {
      name: 'ofarol-front',
      // "current" é um link para a release ativa: cada restart pega a nova.
      script: `${base}/current/dist/server/entry.mjs`,
      cwd: `${base}/current`,
      interpreter: process.env.BUN || `${home}/.bun/bin/bun`,
      interpreter_args: `--env-file=${base}/.env`,
      env: { NODE_ENV: 'production' },
      max_memory_restart: '400M',
      restart_delay: 3000,
    },
  ],
};
