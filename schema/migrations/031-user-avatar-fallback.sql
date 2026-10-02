UPDATE `users`
SET `img` = NULL
WHERE TRIM(COALESCE(`img`, '')) IN (
  '/static/img/jyavani.svg',
  '/static/img/person.svg'
)
OR LOWER(TRIM(COALESCE(`img`, ''))) LIKE 'https://ui-avatars.com/api/%';
