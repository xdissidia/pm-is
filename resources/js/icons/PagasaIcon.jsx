import { Center, rem } from '@mantine/core';

export default function PagasaIcon({ size = 16, ...props }) {
  return (
    <Center
      bg='white'
      p={Math.round(size * 0.18)}
      style={{ borderRadius: rem(Math.round(size * 0.25)) }}
      {...props}
    >
      <img
        src='/assets/images/pagasa-logo.png'
        alt=''
        style={{ width: rem(size), height: rem(size), display: 'block' }}
      />
    </Center>
  );
}
