# Railway-backed Laravel backups

Set up first-pass disaster-recovery backups for Voltikka by creating a Railway S3-compatible bucket and configuring Laravel to send database backups there. Railway bucket is same-provider redundancy for now; external off-provider replication can be added later.
